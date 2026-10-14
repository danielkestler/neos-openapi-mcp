<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Neos\OpenApi\Compilation\CompiledApi;
use Neos\OpenApi\Http\RequestHandler;

/**
 * One API an {@see McpServer} exposes: the tools made from it and the request handler that serves them.
 *
 * The catalog is taken ready-made, so an application can build it once and cache it — building it converts and
 * bundles every schema of the document, which is not work to repeat on every request.
 */
final readonly class ExposedApi
{
    /**
     * @param string $pathPrefix where the API is served, if below a prefix (e.g. `/api`) — only what the
     *                           operation's request shows as its path, since tool calls are routed already
     */
    public function __construct(
        public ToolCatalog $catalog,
        public RequestHandler $requestHandler,
        public string $pathPrefix = '',
    ) {}

    /**
     * @param list<string>|null $includeTags see {@see ToolCatalog::fromCompiledApi()}
     * @param list<string> $excludeTags see {@see ToolCatalog::fromCompiledApi()}
     */
    public static function fromCompiledApi(
        CompiledApi $api,
        RequestHandler $requestHandler,
        array|null $includeTags = null,
        array $excludeTags = [],
        string $pathPrefix = '',
    ): self {
        return new self(ToolCatalog::fromCompiledApi($api, $includeTags, $excludeTags), $requestHandler, $pathPrefix);
    }
}
