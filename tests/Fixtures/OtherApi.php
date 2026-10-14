<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Neos\OpenApi\Attributes\Operation;

/**
 * A second API with an operationId the ArticleApi has too.
 */
final class OtherApi
{
    #[Operation(path: '/other/articles', method: 'GET')]
    public function listArticles(): string
    {
        return 'other';
    }

    #[Operation(path: '/other/status', method: 'GET')]
    public function otherStatus(): string
    {
        return 'fine';
    }
}
