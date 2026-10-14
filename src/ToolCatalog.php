<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Neos\OpenApi\Compilation\CompiledApi;
use Neos\OpenApi\Dispatch\ArgumentSource;
use Neos\OpenApi\Dispatch\DispatchEntry;
use Neos\OpenApi\Mcp\Exception\InvalidToolException;
use Neos\OpenApi\Mcp\Exception\ToolNameCollisionException;
use Neos\OpenApi\Mcp\Schema\ComponentBundler;
use Neos\OpenApi\Mcp\Schema\InputSchema;
use Neos\OpenApi\Mcp\Schema\JsonValue;
use Neos\OpenApi\Mcp\Schema\OutputSchema;
use Neos\OpenApi\Spec\OperationObject;
use Neos\OpenApi\Spec\ParameterObject;
use Neos\OpenApi\Spec\PathObject;
use Neos\OpenApi\Spec\ReferenceObject;
use Neos\OpenApi\Spec\RequestBodyObject;
use Neos\OpenApi\Support\HttpMethod;
use Traversable;

/**
 * The operations of a {@see CompiledApi}, as MCP tools.
 *
 * Built from the Dispatch Table rather than from the document's paths, for two reasons: its order is the order the
 * operations were declared in — deterministic, and what a developer reading `tools/list` expects — and it only
 * holds operations something actually answers.
 *
 * Some operations cannot be tools (yet), and are left out rather than published half-working. {@see $skipped}
 * says which and why:
 *
 * - a request body that is not JSON — a multipart upload has no JSON Schema a client could fill in;
 * - an operation that takes the raw request and a body the document does not describe: what it reads from it, e.g. a
 *   multipart upload, is unknown. Taking the raw request alone is fine — e.g. to build links from the request's URI —
 *   since the invoker hands over a complete request with every documented input.
 *
 * Everything else that does not fit — an operationId that is no valid tool name, two inputs sharing a name — is an
 * {@see InvalidToolException}: those are mistakes in the API, and dropping the operation quietly would hide them.
 *
 * @implements IteratorAggregate<int, OperationTool>
 */
final readonly class ToolCatalog implements IteratorAggregate, Countable
{
    /**
     * @param array<string, OperationTool> $tools tool name => tool, in catalog order
     * @param array<string, string> $skipped operationId => why it is not a tool
     */
    private function __construct(
        private array $tools,
        public array $skipped,
    ) {}

    /**
     * @param list<string>|null $includeTags only operations carrying at least one of these OpenAPI tags; `null`
     *                                       for all of them
     * @param list<string> $excludeTags no operation carrying any of these tags, whatever $includeTags says
     * @throws InvalidToolException if an operation cannot be described as a tool
     */
    public static function fromCompiledApi(CompiledApi $api, array|null $includeTags = null, array $excludeTags = []): self
    {
        $bundler = ComponentBundler::forDocument($api->document);
        $tools = [];
        $skipped = [];
        foreach ($api->dispatchTable as $entry) {
            $pathObject = $api->document->paths?->get($entry->path);
            $operation = $pathObject?->operation($entry->method);
            if ($pathObject === null || $operation === null) {
                throw new \LogicException(sprintf(
                    'The Dispatch Table has an entry for "%s %s" but the document does not describe it',
                    $entry->method->value,
                    $entry->path->value,
                ), 1791460401);
            }
            if (!self::isIncluded($operation->tags ?? [], $includeTags, $excludeTags)) {
                continue;
            }
            $reason = self::whyNotATool($entry, $operation);
            if ($reason !== null) {
                $skipped[$entry->operationId] = $reason;
                continue;
            }
            $tool = self::toolFor($entry, $pathObject, $operation, $api, $bundler);
            $tools[$tool->name] = $tool;
        }
        return new self($tools, $skipped);
    }

    /**
     * A catalog of tools built some other way — or a selection of another catalog's.
     *
     * @throws ToolNameCollisionException if two of them share a name
     */
    public static function fromTools(OperationTool ...$tools): self
    {
        return self::empty()->with(...$tools);
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @throws ToolNameCollisionException if one of them shares a name with a tool already in this catalog, or with
     *                                    another one of them
     */
    public function with(OperationTool ...$tools): self
    {
        $merged = $this->tools;
        foreach ($tools as $tool) {
            if (isset($merged[$tool->name])) {
                throw new ToolNameCollisionException(sprintf(
                    'There are two tools named "%s" (operationIds "%s" and "%s"). MCP addresses a tool by its name '
                    . 'alone, so one would silently replace the other. To solve this, give one of the operations a '
                    . 'different operationId, or leave one of them out by its tag.',
                    $tool->name,
                    $merged[$tool->name]->operationId,
                    $tool->operationId,
                ), 1791460402);
            }
            $merged[$tool->name] = $tool;
        }
        return new self($merged, $this->skipped);
    }

    /**
     * Both catalogs' tools in one, for one MCP server exposing several APIs.
     *
     * @throws ToolNameCollisionException if a tool name occurs in both
     */
    public function merge(self $other): self
    {
        $merged = $this->with(...array_values($other->tools));
        return new self($merged->tools, [...$this->skipped, ...$other->skipped]);
    }

    /**
     * @param callable(OperationTool): bool $predicate
     */
    public function filter(callable $predicate): self
    {
        return new self(array_filter($this->tools, $predicate), $this->skipped);
    }

    public function get(string $name): OperationTool|null
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function count(): int
    {
        return count($this->tools);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->tools));
    }

    /**
     * @param list<string> $tags
     * @param list<string>|null $includeTags
     * @param list<string> $excludeTags
     */
    private static function isIncluded(array $tags, array|null $includeTags, array $excludeTags): bool
    {
        if (array_intersect($tags, $excludeTags) !== []) {
            return false;
        }
        return $includeTags === null || array_intersect($tags, $includeTags) !== [];
    }

    private static function whyNotATool(DispatchEntry $entry, OperationObject $operation): string|null
    {
        foreach ($entry->arguments as $argument) {
            if ($argument->source === ArgumentSource::request && $operation->requestBody === null && in_array($entry->method, [HttpMethod::POST, HttpMethod::PUT, HttpMethod::PATCH], true)) {
                return 'It takes the raw request and a body the document does not describe';
            }
        }
        if ($operation->requestBody instanceof ReferenceObject) {
            return 'Its request body is a reference, which is not supported';
        }
        if ($operation->requestBody !== null && self::jsonMediaType($operation->requestBody) === null) {
            return sprintf(
                'Its request body is not JSON (%s), and only JSON bodies can be passed as a tool argument',
                implode(', ', array_keys(iterator_to_array($operation->requestBody->content))),
            );
        }
        return null;
    }

    private static function toolFor(
        DispatchEntry $entry,
        PathObject $pathObject,
        OperationObject $operation,
        CompiledApi $api,
        ComponentBundler $bundler,
    ): OperationTool {
        $parameters = self::parametersOf($entry->operationId, $pathObject, $operation);
        $requestBody = $operation->requestBody instanceof RequestBodyObject ? $operation->requestBody : null;
        $bodyMediaType = $requestBody === null ? null : self::jsonMediaType($requestBody);

        $body = null;
        if ($requestBody !== null && $bodyMediaType !== null) {
            $bodySchema = null;
            foreach ($requestBody->content as $range => $mediaTypeObject) {
                if ($range === $bodyMediaType) {
                    $bodySchema = $mediaTypeObject->schema;
                }
            }
            $body = new ToolRequestBody(
                contentType: str_contains($bodyMediaType, '*') ? 'application/json' : $bodyMediaType,
                required: $requestBody->required === true,
                isObject: $bodySchema !== null && $bundler->describesObject(JsonValue::objectFromSerializable($bodySchema)),
            );
        }

        return new OperationTool(
            name: $entry->operationId,
            title: $operation->summary,
            description: self::descriptionOf($entry, $operation),
            inputSchema: InputSchema::build($entry->operationId, $parameters, $requestBody, $bodyMediaType, $bundler),
            outputSchema: OutputSchema::build($entry->operationId, $operation, $bundler),
            annotations: OperationTool::annotationsFor($entry->method),
            operationId: $entry->operationId,
            security: $operation->security ?? $api->document->security,
            method: $entry->method,
            path: $entry->path,
            parameters: array_map(
                static fn(ParameterObject $parameter): ToolParameter => new ToolParameter(
                    name: $parameter->name,
                    in: $parameter->in,
                    required: $parameter->required === true,
                    style: $parameter->style,
                    explode: $parameter->explode,
                ),
                $parameters,
            ),
            body: $body,
            tags: $operation->tags ?? [],
        );
    }

    /**
     * The operation's parameters, with those declared on its path merged in — an operation's own parameter
     * overrides the path's one of the same name and location, as the specification says.
     *
     * @return list<ParameterObject>
     */
    private static function parametersOf(string $operationId, PathObject $pathObject, OperationObject $operation): array
    {
        $parameters = [];
        foreach ([$pathObject->parameters ?? [], $operation->parameters ?? []] as $declared) {
            foreach ($declared as $parameter) {
                if (!$parameter instanceof ParameterObject) {
                    throw new InvalidToolException(sprintf(
                        'The operation "%s" refers to a parameter by reference ("%s"), which is not supported',
                        $operationId,
                        $parameter->ref,
                    ), 1791460403);
                }
                $parameters[$parameter->in->value . ':' . $parameter->name] = $parameter;
            }
        }
        return array_values($parameters);
    }

    /**
     * The JSON media type an operation's body is sent as — a concrete `application/json` first, then a `+json`
     * type, then a range covering JSON (`application/*`, `*\/*`). `null` if none of them is there.
     */
    private static function jsonMediaType(RequestBodyObject $requestBody): string|null
    {
        $ranges = array_map('strval', array_keys(iterator_to_array($requestBody->content)));
        if (in_array('application/json', $ranges, true)) {
            return 'application/json';
        }
        foreach ($ranges as $range) {
            if (!str_contains($range, '*') && OutputSchema::isJson($range)) {
                return $range;
            }
        }
        foreach ($ranges as $range) {
            if (in_array(strtolower(explode(';', $range, 2)[0]), ['application/*', '*/*'], true)) {
                return $range;
            }
        }
        return null;
    }

    /**
     * What a model reads to decide whether to call the tool: the summary, the description, and the HTTP operation
     * itself — a developer debugging a tool call will want to know which endpoint it hit.
     */
    private static function descriptionOf(DispatchEntry $entry, OperationObject $operation): string
    {
        $paragraphs = [];
        foreach ([$operation->summary, $operation->description] as $text) {
            $text = trim((string) $text);
            if ($text !== '' && !in_array($text, $paragraphs, true)) {
                $paragraphs[] = $text;
            }
        }
        if ($operation->deprecated === true) {
            $paragraphs[] = 'Deprecated: avoid using this tool.';
        }
        $paragraphs[] = sprintf('%s %s', $entry->method->value, $entry->path->value);
        return implode("\n\n", $paragraphs);
    }
}
