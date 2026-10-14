<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

final readonly class Caller
{
    public function __construct(
        public string $name,
    ) {}
}
