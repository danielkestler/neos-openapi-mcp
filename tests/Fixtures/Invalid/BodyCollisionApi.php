<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures\Invalid;

use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Attributes\RequestBody;
use Neos\OpenApi\Mcp\Tests\Fixtures\NewArticle;
use Neos\OpenApi\Support\ParameterLocation;

final class BodyCollisionApi
{
    #[Operation(path: '/things', method: 'POST')]
    public function createThing(
        #[Parameter(in: ParameterLocation::query)]
        string $body,
        #[RequestBody]
        NewArticle $thing,
    ): void {}
}
