<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTTP request a tool call turns into, together with what a router would have extracted from it.
 *
 * The path variables travel separately because `RequestHandler::handleOperation()` takes them that way — the
 * request is already routed, so nothing should have to parse its path again.
 */
final readonly class OperationRequest
{
    /**
     * @param array<string, string> $pathVariables the path template's variables, *not* percent-encoded
     */
    public function __construct(
        public ServerRequestInterface $request,
        public string $operationId,
        public array $pathVariables,
    ) {}
}
