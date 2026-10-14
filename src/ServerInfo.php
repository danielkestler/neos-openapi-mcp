<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

/**
 * How the MCP server introduces itself — in the `serverInfo` of every result and in `server/discover`.
 */
final readonly class ServerInfo
{
    public function __construct(
        public string $name,
        public string $version,
        /**
         * A display name, where `name` is an identifier
         */
        public string|null $title = null,
        public string|null $description = null,
        /**
         * Guidance for the model on how to use the server's tools, which clients may add to its context
         */
        public string|null $instructions = null,
        public string|null $websiteUrl = null,
    ) {}
}
