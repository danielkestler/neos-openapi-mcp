<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Internal;

use Closure;
use Mcp\Capability\Registry\Loader\ExplicitElementLoader;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Registers the tools a caller may see — but only once the SDK first needs its registry.
 *
 * Deciding visibility asks the application's AuthContextProvider, which may validate a token or hit a database.
 * A `server/discover` needs no tools at all, so the work is deferred to the SDK's lazy loading.
 *
 * @internal
 */
final readonly class VisibleToolLoader implements LoaderInterface
{
    /**
     * @param Closure(): list<array{definition: Tool, handler: ToolHandlerInterface}> $tools
     */
    public function __construct(
        private Closure $tools,
    ) {}

    public function load(RegistryInterface $registry): void
    {
        (new ExplicitElementLoader(($this->tools)()))->load($registry);
    }
}
