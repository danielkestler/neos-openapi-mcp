<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Neos\OpenApi\Http\RequestHandler;
use Neos\OpenApi\Mcp\Internal\MiddlewarePipeline;
use Neos\OpenApi\Mcp\Schema\JsonValue;
use Neos\OpenApi\Support\ParameterLocation;
use Neos\OpenApi\Support\ParameterStyle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Carries out a tool call: turns its arguments into the HTTP request the operation answers, hands that to the
 * API's {@see RequestHandler} through the application's middlewares, and turns the response into a tool result.
 *
 * Going through a real PSR-7 request rather than calling the Api Class directly is the point: everything the
 * request handler does — authentication, coercing and validating every argument, serializing the result through
 * the type's own schema, answering with problem documents — happens exactly as it does over HTTP. A tool cannot
 * accept what the endpoint would reject, nor answer differently than it would.
 *
 * The request is **routed already**: it goes to `handleOperation()` with the operationId and the path variables,
 * so the path it carries is informational — what a middleware or a log sees — and no router is involved.
 *
 * What the MCP request contributes: its scheme, host and port (so the operation sees the same origin), its server
 * params, and a configurable set of headers — `Authorization` above all, which is how the caller's credentials
 * reach the operation's `AuthContextProvider`. Cookies are deliberately not forwarded by default: an MCP endpoint
 * that accepted a browser session would be open to cross-site requests.
 *
 * Exceptions the operation (or a middleware) throws are not caught here. Turning a domain exception into a status
 * code is the application's decision, made in a middleware — and the MCP SDK reports whatever still escapes as a
 * JSON-RPC internal error, with the exception logged but its message withheld from the client.
 */
final readonly class OperationInvoker
{
    /**
     * Headers of the MCP request that are copied onto the operation's request, unless a header parameter of the
     * tool sets one of them itself.
     */
    public const DEFAULT_FORWARDED_HEADERS = [
        'Authorization',
        'User-Agent',
        'Forwarded',
        'X-Forwarded-For',
        'X-Forwarded-Host',
        'X-Forwarded-Port',
        'X-Forwarded-Proto',
        'X-Forwarded-Prefix',
        'traceparent',
        'tracestate',
    ];

    /**
     * @param list<MiddlewareInterface> $middlewares wrapped around the operation, the first one outermost — the
     *                                               place to turn application exceptions into problem responses
     * @param string $pathPrefix prepended to the operation's path, if the API is served below one (e.g. `/api`);
     *                           informational only, since the request is already routed
     * @param list<string> $forwardedHeaders headers copied over from the MCP request
     */
    public function __construct(
        private RequestHandler $requestHandler,
        private ServerRequestFactoryInterface $serverRequestFactory,
        private StreamFactoryInterface $streamFactory,
        private array $middlewares = [],
        private string $pathPrefix = '',
        private array $forwardedHeaders = self::DEFAULT_FORWARDED_HEADERS,
        private ResponseMapper $responseMapper = new ResponseMapper(),
    ) {}

    /**
     * Calls the tool's operation with the given arguments, on behalf of whoever sent the MCP request.
     *
     * @param array<string, mixed> $arguments the tool call's arguments, as the MCP SDK decoded them
     * @param ServerRequestInterface $mcpRequest the HTTP request that carried the tool call
     */
    public function invoke(OperationTool $tool, array $arguments, ServerRequestInterface $mcpRequest): CallToolResult
    {
        try {
            $operationRequest = $this->buildRequest($tool, $arguments, $mcpRequest);
        } catch (ToolCallException $exception) {
            return new CallToolResult([new TextContent($exception->getMessage())], isError: true);
        }
        return $this->responseMapper->toResult($this->dispatch($operationRequest));
    }

    /**
     * Sends an already built request through the middlewares to the operation.
     */
    public function dispatch(OperationRequest $operationRequest): ResponseInterface
    {
        $pipeline = new MiddlewarePipeline(
            $this->middlewares,
            fn(ServerRequestInterface $request): ResponseInterface => $this->requestHandler->handleOperation(
                $request,
                $operationRequest->operationId,
                $operationRequest->pathVariables,
            ),
        );
        return $pipeline->handle($operationRequest->request);
    }

    /**
     * The HTTP request a tool call turns into.
     *
     * Path variables are percent-encoded into the path; query parameters are serialized in their declared style
     * (`form`, exploded by default, or `deepObject` as `name[key]=value`) *and* set as the request's query params —
     * the request handler reads the latter, and the two are kept identical; header and cookie parameters become
     * headers and cookies; the body is JSON. An absent or `null` argument is left out, so the operation's own
     * default applies.
     *
     * @param array<string, mixed> $arguments
     * @throws ToolCallException if an argument cannot be put into the request
     */
    public function buildRequest(OperationTool $tool, array $arguments, ServerRequestInterface $mcpRequest): OperationRequest
    {
        $pathVariables = [];
        $queryPairs = [];
        $queryParams = [];
        $headers = [];
        $cookies = [];
        foreach ($tool->parameters as $parameter) {
            $value = $arguments[$parameter->name] ?? null;
            if ($value === null) {
                if ($parameter->in === ParameterLocation::path) {
                    throw new ToolCallException(sprintf('The argument "%s" is required', $parameter->name));
                }
                continue;
            }
            match ($parameter->in) {
                ParameterLocation::path => $pathVariables[$parameter->name] = self::simple($value, $parameter),
                ParameterLocation::header => $headers[$parameter->name] = self::simple($value, $parameter),
                ParameterLocation::cookie => $cookies[$parameter->name] = self::simple($value, $parameter),
                ParameterLocation::query => self::addQuery($parameter, $value, $queryPairs, $queryParams),
            };
        }

        $path = $tool->path->value;
        foreach ($pathVariables as $name => $value) {
            $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
        }
        $query = implode('&', array_map(
            static fn(array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]),
            $queryPairs,
        ));
        $uri = $mcpRequest->getUri()
            ->withPath(rtrim($this->pathPrefix, '/') . $path)
            ->withQuery($query)
            ->withFragment('');

        $request = $this->serverRequestFactory
            ->createServerRequest($tool->method->value, $uri, $mcpRequest->getServerParams())
            ->withProtocolVersion($mcpRequest->getProtocolVersion())
            ->withQueryParams($queryParams)
            ->withCookieParams($cookies)
            ->withHeader('Accept', 'application/json, ' . ResponseMapper::PROBLEM_CONTENT_TYPE);
        foreach ($this->forwardedHeaders as $name) {
            if ($mcpRequest->hasHeader($name)) {
                $request = $request->withHeader($name, $mcpRequest->getHeader($name));
            }
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($cookies !== []) {
            $request = $request->withHeader('Cookie', implode('; ', array_map(
                static fn(string $name, string $value): string => $name . '=' . rawurlencode($value),
                array_keys($cookies),
                $cookies,
            )));
        }

        $bodyValue = $arguments[ToolRequestBody::ARGUMENT_NAME] ?? null;
        if ($tool->body !== null && $bodyValue !== null) {
            // decoded arguments cannot tell `{}` from `[]`; the body's schema can
            $bodyValue = $bodyValue === [] && $tool->body->isObject ? new \stdClass() : $bodyValue;
            try {
                $encoded = json_encode($bodyValue, JsonValue::ENCODING_FLAGS);
            } catch (\JsonException $exception) {
                throw new ToolCallException('The argument "body" cannot be encoded as JSON: ' . $exception->getMessage(), 0, $exception);
            }
            $request = $request
                ->withHeader('Content-Type', $tool->body->contentType)
                ->withBody($this->streamFactory->createStream($encoded));
        }

        return new OperationRequest($request, $tool->operationId, $pathVariables);
    }

    /**
     * `simple` style, the only one path and header parameters use here: a list is comma-separated, an object is
     * `key,value,…` (or `key=value,…` exploded).
     */
    private static function simple(mixed $value, ToolParameter $parameter): string
    {
        if (!is_array($value)) {
            return self::scalar($value, $parameter->name);
        }
        $parts = [];
        foreach ($value as $key => $member) {
            $member = self::scalar($member, $parameter->name);
            if (array_is_list($value)) {
                $parts[] = $member;
            } elseif ($parameter->effectiveExplode()) {
                $parts[] = $key . '=' . $member;
            } else {
                array_push($parts, (string) $key, $member);
            }
        }
        return implode(',', $parts);
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs the query string, in order
     * @param array<string, mixed> $params the same, as PHP would parse it
     */
    private static function addQuery(ToolParameter $parameter, mixed $value, array &$pairs, array &$params): void
    {
        $name = $parameter->name;
        if (!is_array($value)) {
            $pairs[] = [$name, $params[$name] = self::scalar($value, $name)];
            return;
        }
        $style = $parameter->effectiveStyle();
        if ($style === ParameterStyle::deepObject) {
            $params[$name] = self::deepObject($name, $value, $pairs, $name);
            return;
        }
        $delimiter = match ($style) {
            ParameterStyle::spaceDelimited => ' ',
            ParameterStyle::pipeDelimited => '|',
            default => ',',
        };
        $scalars = array_map(static fn(mixed $member): string => self::scalar($member, $name), $value);
        if (array_is_list($value)) {
            if ($parameter->effectiveExplode()) {
                // `name=a&name=b` on the wire; a PHP server reads the list from the query params set alongside
                foreach ($scalars as $member) {
                    $pairs[] = [$name, $member];
                }
                $params[$name] = $scalars;
                return;
            }
            $pairs[] = [$name, $params[$name] = implode($delimiter, $scalars)];
            return;
        }
        if ($parameter->effectiveExplode()) {
            // an exploded `form` object spreads its members into parameters of their own
            foreach ($scalars as $key => $member) {
                $pairs[] = [(string) $key, $params[(string) $key] = $member];
            }
            return;
        }
        $flat = [];
        foreach ($scalars as $key => $member) {
            array_push($flat, (string) $key, $member);
        }
        $pairs[] = [$name, $params[$name] = implode($delimiter, $flat)];
    }

    /**
     * `name[key]=value`, recursively — `name[key][]=value` for a list inside — which is what PHP parses back into
     * the same nested array.
     *
     * @param array<mixed> $value
     * @param list<array{0: string, 1: string}> $pairs
     * @return array<mixed> the value with every leaf as the string it was sent as
     */
    private static function deepObject(string $prefix, array $value, array &$pairs, string $argumentName): array
    {
        $isList = array_is_list($value);
        $parsed = [];
        foreach ($value as $key => $member) {
            if ($member === null) {
                continue;
            }
            $wireName = $prefix . ($isList ? '[]' : '[' . $key . ']');
            if (is_array($member)) {
                $parsedMember = self::deepObject($wireName, $member, $pairs, $argumentName);
            } else {
                $parsedMember = self::scalar($member, $argumentName);
                $pairs[] = [$wireName, $parsedMember];
            }
            if ($isList) {
                $parsed[] = $parsedMember;
            } else {
                $parsed[$key] = $parsedMember;
            }
        }
        return $parsed;
    }

    /**
     * A JSON scalar as the string it travels as in a URI or header: booleans as `true`/`false`, as JSON spells
     * them and as the request handler's normalizer reads them back.
     */
    private static function scalar(mixed $value, string $argumentName): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => throw new ToolCallException(sprintf(
                'The argument "%s" has a %s where a single value is expected',
                $argumentName,
                get_debug_type($value),
            )),
        };
    }
}
