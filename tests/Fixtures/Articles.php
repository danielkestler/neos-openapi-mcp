<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

final readonly class Articles implements ProvidesSchema
{
    /**
     * @var list<Article>
     */
    public array $articles;

    public function __construct(Article ...$articles)
    {
        $this->articles = array_values($articles);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
