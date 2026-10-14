<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures\Invalid;

use Neos\OpenApi\Attributes\Operation;

final class InvalidNameApi
{
    #[Operation(path: '/things', method: 'GET', operationId: 'list things!')]
    public function listThings(): string
    {
        return '';
    }
}
