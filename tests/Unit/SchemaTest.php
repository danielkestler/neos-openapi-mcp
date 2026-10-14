<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Unit;

use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ReferenceSchema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\OpenApi\Mcp\Exception\InvalidToolException;
use Neos\OpenApi\Mcp\OperationTool;
use Neos\OpenApi\Mcp\Schema\ComponentBundler;
use Neos\OpenApi\Mcp\ToolCatalog;
use Neos\OpenApi\Mcp\Tests\Fixtures\TestApis;
use Neos\OpenApi\Spec\ComponentsObject;
use Neos\OpenApi\Spec\InfoObject;
use Neos\OpenApi\Spec\OpenApiObject;
use Neos\OpenApi\Spec\SchemaObjectMap;
use PHPUnit\Framework\TestCase;

/**
 * The input and output schemas, judged by the JSON a client receives — which is where `{}` versus `[]` and a
 * dangling `$ref` would show.
 */
final class SchemaTest extends TestCase
{
    private ToolCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = ToolCatalog::fromCompiledApi(TestApis::articles());
    }

    /**
     * @return array<string, mixed>
     */
    private function published(string $toolName): array
    {
        $tool = $this->catalog->get($toolName);
        self::assertInstanceOf(OperationTool::class, $tool);
        $decoded = json_decode((string) json_encode($tool->toMcpTool()), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testAnOperationWithoutInputHasAnEmptyClosedObjectSchema(): void
    {
        $json = (string) json_encode($this->catalog->get('ping')?->toMcpTool());

        self::assertStringContainsString('"inputSchema":{"type":"object","additionalProperties":false}', $json);
    }

    public function testParametersAreTopLevelPropertiesAndPathParametersAreRequired(): void
    {
        $schema = $this->published('tagArticle')['inputSchema'];

        self::assertSame('object', $schema['type']);
        self::assertSame(['articleId', 'tag'], array_keys($schema['properties']));
        self::assertSame(['articleId', 'tag'], $schema['required']);
        self::assertSame(['type' => 'string'], $schema['properties']['tag']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testAnOptionalQueryParameterIsNotRequired(): void
    {
        $schema = $this->published('listArticles')['inputSchema'];

        self::assertArrayNotHasKey('required', $schema);
        self::assertSame(['type' => 'integer'], $schema['properties']['limit']);
    }

    public function testAHeaderParameterIsAPropertyUnderItsHeaderNameWithItsDescription(): void
    {
        $schema = $this->published('listArticles')['inputSchema'];

        self::assertSame(
            ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'The preferred language'],
            $schema['properties']['Accept-Language'],
        );
    }

    public function testADeepObjectParameterIsAnObjectPropertyWithItsComponentBundled(): void
    {
        $schema = $this->published('listArticles')['inputSchema'];

        self::assertSame(
            ['anyOf' => [['$ref' => '#/$defs/ArticleFilter'], ['type' => 'null']]],
            $schema['properties']['filter'],
        );
        // ArticleFilter refers to AuthorName, which is bundled too — and nothing else is
        self::assertSame(['ArticleFilter', 'AuthorName'], array_keys($schema['$defs']));
        self::assertSame(
            ['anyOf' => [['$ref' => '#/$defs/AuthorName'], ['type' => 'null']]],
            $schema['$defs']['ArticleFilter']['properties']['author'],
        );
    }

    public function testTheRequestBodyIsThePropertyBodyRequiredIfTheBodyIs(): void
    {
        $schema = $this->published('createArticle')['inputSchema'];

        self::assertSame(['$ref' => '#/$defs/NewArticle', 'description' => 'The article to create'], $schema['properties']['body']);
        self::assertSame(['body'], $schema['required']);
        self::assertSame(['NewArticle'], array_keys($schema['$defs']));
        self::assertSame(['title'], $schema['$defs']['NewArticle']['required']);
    }

    public function testComponentReferencesAreRewrittenAndOnlyTheReachableOnesBundled(): void
    {
        $output = $this->published('getArticle')['outputSchema'];

        self::assertSame('#/$defs/Article', $output['$ref']);
        self::assertEqualsCanonicalizing(['Article', 'ArticleId', 'Author', 'AuthorName'], array_keys($output['$defs']));
        self::assertSame('#/$defs/Author', $output['$defs']['Article']['properties']['author']['$ref']);
        self::assertStringNotContainsString('#/components/', (string) json_encode($output));
    }

    public function testTheOutputSchemaIsTheFirstSuccessfulJsonResponses(): void
    {
        // a 201 that is an ApiResponse with a JSON body
        self::assertSame('#/$defs/Article', $this->published('createArticle')['outputSchema']['$ref']);
        // a scalar is a fine output schema since 2026-07-28 (SEP-2106)
        self::assertSame(['type' => 'string'], $this->published('ping')['outputSchema']);
    }

    public function testAnOperationWithoutAJsonSuccessResponseHasNoOutputSchema(): void
    {
        self::assertArrayNotHasKey('outputSchema', $this->published('deleteArticle'));
        self::assertArrayNotHasKey('outputSchema', $this->published('tagArticle'));
    }

    public function testBundlingFollowsReferencesTransitivelyAndSurvivesRecursion(): void
    {
        $bundler = ComponentBundler::forDocument(self::document([
            'Tree' => ObjectSchema::create(properties: ObjectProperties::create(
                label: SchemaObjectMap::reference('Label'),
                children: ArraySchema::create(items: SchemaObjectMap::reference('Tree')),
            )),
            'Label' => StringSchema::create(),
            'Unused' => StringSchema::create(),
        ]));

        $bundled = $bundler->bundle(['$ref' => '#/components/schemas/Tree'], 'op');

        self::assertIsArray($bundled);
        self::assertSame(['Tree', 'Label'], array_keys($bundled['$defs']));
        self::assertSame('#/$defs/Tree', $bundled['$defs']['Tree']['properties']['children']['items']['$ref']);
    }

    public function testAReferenceToAMissingComponentFailsLoudly(): void
    {
        $bundler = ComponentBundler::forDocument(self::document([]));

        $this->expectException(InvalidToolException::class);
        $this->expectExceptionMessage('"Missing"');

        $bundler->bundle(['$ref' => '#/components/schemas/Missing'], 'op');
    }

    public function testAReferenceOutsideTheComponentSchemasFailsLoudly(): void
    {
        $bundler = ComponentBundler::forDocument(self::document([
            'Odd' => ReferenceSchema::create('https://example.com/schema.json'),
        ]));

        $this->expectException(InvalidToolException::class);
        $this->expectExceptionMessage('https://example.com/schema.json');

        $bundler->bundle(['$ref' => '#/components/schemas/Odd'], 'op');
    }

    /**
     * @param array<string, \Neos\JsonSchema\Schema> $schemas
     */
    private static function document(array $schemas): OpenApiObject
    {
        $map = SchemaObjectMap::create();
        foreach ($schemas as $name => $schema) {
            $map = $map->with($name, $schema);
        }
        return new OpenApiObject(info: new InfoObject(title: 'Test', version: '1'), components: new ComponentsObject(schemas: $map));
    }
}
