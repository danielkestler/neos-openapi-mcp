<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Refers to two other components (ArticleId and Author, which refers to AuthorName), so bundling has to follow
 * references transitively.
 */
final readonly class Article implements ProvidesSchema
{
    public function __construct(
        public ArticleId $id,
        public string $title,
        public Author $author,
        public bool $published = false,
    ) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
