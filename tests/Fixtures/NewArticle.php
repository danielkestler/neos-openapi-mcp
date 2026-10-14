<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

final readonly class NewArticle implements ProvidesSchema
{
    public function __construct(
        public string $title,
        public string $author = 'anonymous',
    ) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                title: StringSchema::create(minLength: 1),
                author: StringSchema::create(minLength: 1),
            ),
            additionalProperties: false,
            required: ['title'],
        );
    }
}
