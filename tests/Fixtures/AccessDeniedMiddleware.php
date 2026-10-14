<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What the Flow app does with its own access exceptions: a 403 problem document.
 */
final class AccessDeniedMiddleware implements MiddlewareInterface
{
    /**
     * @var list<ServerRequestInterface>
     */
    public array $seen = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->seen[] = $request;
        try {
            return $handler->handle($request);
        } catch (AccessDenied $exception) {
            return new Response(403, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                'type' => 'https://www.rfc-editor.org/rfc/rfc9110#status.403',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => $exception->getMessage(),
            ]));
        }
    }
}
