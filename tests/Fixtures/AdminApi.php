<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Neos\OpenApi\Attributes\Operation;

final class AdminApi
{
    #[Operation(path: '/admin/caches/flush', method: 'POST', summary: 'Flush all caches', security: 'adminAuth')]
    public function flushCaches(): void {}
}
