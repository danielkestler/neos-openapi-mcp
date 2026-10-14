<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * Nothing required, so `{}` is a valid body — which a decoded tool call delivers as `[]`.
 */
final readonly class ArticlePatch implements ProvidesSchema
{
    public function __construct(
        public string|null $title = null,
    ) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                title: StringSchema::create(minLength: 1),
            ),
            additionalProperties: false,
        );
    }
}
