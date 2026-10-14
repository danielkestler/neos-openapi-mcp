<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Internal;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs a request through a list of PSR-15 middlewares, the first one outermost, and finally into a closure.
 *
 * @internal
 */
final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     * @param Closure(ServerRequestInterface): ResponseInterface $core
     */
    public function __construct(
        private array $middlewares,
        private Closure $core,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->middlewares === []) {
            return ($this->core)($request);
        }
        [$outermost, $rest] = [$this->middlewares[0], array_slice($this->middlewares, 1)];
        return $outermost->process($request, new self($rest, $this->core));
    }
}
