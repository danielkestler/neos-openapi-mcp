<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\StatelessHttpTransport;
use Neos\OpenApi\Http\AuthContextProvider;
use Neos\OpenApi\Mcp\Exception\ToolNameCollisionException;
use Neos\OpenApi\Mcp\Internal\OperationToolHandler;
use Neos\OpenApi\Mcp\Internal\VisibleToolLoader;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * An MCP endpoint exposing the operations of one or more APIs as tools: hand it the HTTP request, get the HTTP
 * response back.
 *
 * It speaks the **stateless 2026-07-28 revision only**. There is no `initialize` handshake, no session, no
 * `Mcp-Session-Id`: every request carries its protocol version and client capabilities in `params._meta` and in
 * the `MCP-Protocol-Version` header, and is answered on its own — one POST in, one JSON response out. A client
 * speaking an older revision is told which one this server supports. That is what lets it run as an ordinary
 * PHP request handler with nothing to keep between requests.
 *
 * Per request, it decides which tools the caller may see ({@see ToolVisibility}, if an {@see AuthContextProvider}
 * is given), registers those with a fresh SDK server, and lets the SDK's stateless HTTP transport answer.
 *
 * Origin validation: the transport middlewares default to the SDK's own — CORS (no cross-origin access) and DNS
 * rebinding protection, which rejects any `Origin` or `Host` that is not localhost. That is right for a local
 * server and wrong for a deployed one: pass your own list, e.g.
 * `[new CorsMiddleware(), new DnsRebindingProtectionMiddleware(['neos.example.com'])]`, or `[]` if a layer in front
 * validates `Origin` already.
 */
final readonly class McpServer implements RequestHandlerInterface
{
    /**
     * @var list<array{tool: OperationTool, invoker: OperationInvoker}>
     */
    private array $tools;

    /**
     * @param list<ExposedApi> $apis
     * @param list<MiddlewareInterface> $operationMiddlewares wrapped around every operation call, the first one
     *                                                        outermost — see {@see OperationInvoker}
     * @param AuthContextProvider|null $authContextProvider decides which tools a caller sees; without one, every
     *                                                      tool is listed to everyone (and refused on call if the
     *                                                      caller is not authorized)
     * @param list<MiddlewareInterface>|null $transportMiddlewares wrapped around the MCP endpoint itself; `null`
     *                                                             for the SDK's defaults (localhost only!)
     * @param list<string> $forwardedHeaders headers of the MCP request that operation calls carry over
     * @throws ToolNameCollisionException if two of the APIs have a tool of the same name
     */
    public function __construct(
        private ServerInfo $serverInfo,
        array $apis,
        ServerRequestFactoryInterface $serverRequestFactory,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        array $operationMiddlewares = [],
        private AuthContextProvider|null $authContextProvider = null,
        private array|null $transportMiddlewares = null,
        private LoggerInterface $logger = new NullLogger(),
        array $forwardedHeaders = OperationInvoker::DEFAULT_FORWARDED_HEADERS,
    ) {
        $all = ToolCatalog::empty();
        $tools = [];
        foreach ($apis as $api) {
            // merged only to have the collision checked up front, rather than on a request that lists both
            $all = $all->merge($api->catalog);
            $invoker = new OperationInvoker(
                $api->requestHandler,
                $serverRequestFactory,
                $streamFactory,
                $operationMiddlewares,
                $api->pathPrefix,
                $forwardedHeaders,
            );
            foreach ($api->catalog as $tool) {
                $tools[] = ['tool' => $tool, 'invoker' => $invoker];
            }
        }
        $this->tools = $tools;
    }

    /**
     * Answers one MCP request.
     *
     * Anything an operation throws that its middlewares do not turn into a response is caught by the SDK and
     * answered as a JSON-RPC internal error (logged to the logger given, message withheld from the client).
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $transport = new StatelessHttpTransport(
            $this->protocolFor($request),
            $this->responseFactory,
            $this->streamFactory,
            $this->logger,
            middleware: $this->transportMiddlewares,
        );
        return $transport->handle($request);
    }

    /**
     * The tools the caller of this request may see.
     */
    public function visibleTools(ServerRequestInterface $request): ToolCatalog
    {
        $catalog = ToolCatalog::fromTools(...array_map(static fn(array $entry): OperationTool => $entry['tool'], $this->tools));
        return $this->authContextProvider === null
            ? $catalog
            : (new ToolVisibility($this->authContextProvider))->visibleTools($catalog, $request);
    }

    /**
     * A dispatcher for this one request, with the caller's tools.
     *
     * Built per request because the tool list depends on who is asking — and because there is nothing to keep:
     * the stateless revision has no session to hold on to.
     */
    private function protocolFor(ServerRequestInterface $request): StatelessProtocol
    {
        $info = $this->serverInfo;
        $builder = Server::builder()
            ->setServerInfo($info->name, $info->version, $info->description, websiteUrl: $info->websiteUrl, title: $info->title)
            ->setInstructions($info->instructions)
            // only tools: auto-detection would advertise resources, prompts, completions and logging too
            ->setCapabilities(new ServerCapabilities(
                tools: true,
                toolsListChanged: false,
                resources: false,
                resourcesSubscribe: false,
                resourcesListChanged: false,
                prompts: false,
                promptsListChanged: false,
                logging: false,
                completions: false,
            ))
            ->setLogger($this->logger)
            ->addLoader(new VisibleToolLoader(fn(): array => $this->registrations($request)));
        return $builder->buildStateless([ProtocolVersion::V2026_07_28]);
    }

    /**
     * @return list<array{definition: Tool, handler: ToolHandlerInterface}>
     */
    private function registrations(ServerRequestInterface $request): array
    {
        $visible = $this->visibleTools($request);
        $registrations = [];
        foreach ($this->tools as $entry) {
            if (!$visible->has($entry['tool']->name)) {
                continue;
            }
            $registrations[] = [
                'definition' => $entry['tool']->toMcpTool(),
                'handler' => new OperationToolHandler($entry['invoker'], $entry['tool'], $request),
            ];
        }
        return $registrations;
    }
}
