<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * An object in the query string, so the compiler declares it `style: deepObject`
 */
final readonly class ArticleFilter implements ProvidesSchema
{
    public function __construct(
        public AuthorName|null $author = null,
        public bool|null $published = null,
    ) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
