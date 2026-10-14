<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Neos\OpenApi\Http\AuthContextProvider;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `Bearer <name>` is the caller of that name, for `bearerAuth`; `Bearer admin` additionally satisfies `adminAuth`.
 * `Bearer broken` throws, as a provider might for a token it cannot parse.
 */
final class TestAuthContextProvider implements AuthContextProvider
{
    public int $calls = 0;

    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): object|null
    {
        $this->calls++;
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $name = substr($header, 7);
        if ($name === 'broken') {
            throw new \RuntimeException('The token cannot be parsed');
        }
        if (in_array('adminAuth', $requirement->schemeNames(), true) && $name !== 'admin') {
            return null;
        }
        return new Caller($name);
    }
}
