<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Unit;

use Neos\OpenApi\ApiDefinition;
use Neos\OpenApi\Compilation\ApiCompiler;
use Neos\OpenApi\Mcp\Exception\InvalidToolException;
use Neos\OpenApi\Mcp\Exception\ToolNameCollisionException;
use Neos\OpenApi\Mcp\ToolCatalog;
use Neos\OpenApi\Mcp\ToolRequestBody;
use Neos\OpenApi\Mcp\Tests\Fixtures\AdminApi;
use Neos\OpenApi\Mcp\Tests\Fixtures\ArticleApi;
use Neos\OpenApi\Mcp\Tests\Fixtures\Invalid\BodyCollisionApi;
use Neos\OpenApi\Mcp\Tests\Fixtures\Invalid\InvalidNameApi;
use Neos\OpenApi\Mcp\Tests\Fixtures\OtherApi;
use Neos\OpenApi\Mcp\Tests\Fixtures\TestApis;
use Neos\OpenApi\Spec\InfoObject;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Neos\OpenApi\Spec\SecuritySchemeObject;
use Neos\OpenApi\Spec\SecuritySchemeOrReferenceObjectMap;
use Neos\OpenApi\Support\HttpMethod;
use Neos\OpenApi\Support\ParameterLocation;
use Neos\OpenApi\Support\ParameterStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolCatalogTest extends TestCase
{
    public function testToolsFollowTheDispatchTableOrder(): void
    {
        $catalog = ToolCatalog::fromCompiledApi(TestApis::articles());

        self::assertSame(
            ['listArticles', 'getArticle', 'createArticle', 'updateArticle', 'deleteArticle', 'tagArticle', 'ping', 'whoAmI', 'flushCaches'],
            $catalog->names(),
        );
        self::assertCount(9, $catalog);
    }

    public function testOperationsReadingAnUndescribedBodyFromTheRawRequestOrANonJsonBodyAreSkippedWithAReason(): void
    {
        $catalog = ToolCatalog::fromCompiledApi(TestApis::articles());

        self::assertFalse($catalog->has('uploadAttachment'));
        self::assertFalse($catalog->has('importArticle'));
        self::assertSame(['uploadAttachment', 'importArticle'], array_keys($catalog->skipped));
        self::assertStringContainsString('raw request', $catalog->skipped['uploadAttachment']);
        // ping takes the raw request too, but no body
        self::assertTrue($catalog->has('ping'));
        self::assertStringContainsString('multipart/form-data', $catalog->skipped['importArticle']);
    }

    public function testToolsCanBeSelectedByTag(): void
    {
        $api = TestApis::articles();

        self::assertSame(['flushCaches'], ToolCatalog::fromCompiledApi($api, includeTags: ['AdminApi'])->names());
        self::assertNotContains('flushCaches', ToolCatalog::fromCompiledApi($api, excludeTags: ['AdminApi'])->names());
        self::assertSame([], ToolCatalog::fromCompiledApi($api, includeTags: ['AdminApi'], excludeTags: ['AdminApi'])->names());
    }

    public function testATitleDescriptionAndTheHttpOperationAreDerivedFromTheOperation(): void
    {
        $catalog = ToolCatalog::fromCompiledApi(TestApis::articles());

        $list = $catalog->get('listArticles');
        self::assertNotNull($list);
        self::assertSame('List articles', $list->title);
        self::assertSame("List articles\n\nThe articles, newest first.\n\nGET /articles", $list->description);
        self::assertSame(['ArticleApi'], $list->tags);

        $ping = $catalog->get('ping');
        self::assertNotNull($ping);
        self::assertNull($ping->title);
        self::assertSame("Deprecated: avoid using this tool.\n\nGET /ping", $ping->description);
    }

    /**
     * @return iterable<string, array{string, bool, bool, bool}>
     */
    public static function annotations(): iterable
    {
        yield 'GET' => ['getArticle', true, false, true];
        yield 'POST' => ['createArticle', false, false, false];
        yield 'PATCH' => ['updateArticle', false, false, false];
        yield 'PUT' => ['tagArticle', false, false, true];
        yield 'DELETE' => ['deleteArticle', false, true, true];
    }

    #[DataProvider('annotations')]
    public function testAnnotationsFollowTheHttpMethod(string $name, bool $readOnly, bool $destructive, bool $idempotent): void
    {
        $tool = ToolCatalog::fromCompiledApi(TestApis::articles())->get($name);
        self::assertNotNull($tool);

        self::assertSame($readOnly, $tool->annotations->readOnlyHint);
        self::assertSame($destructive, $tool->annotations->destructiveHint);
        self::assertSame($idempotent, $tool->annotations->idempotentHint);
        self::assertFalse($tool->annotations->openWorldHint);
    }

    public function testTheInvokerDataDescribesWhereEachArgumentGoes(): void
    {
        $catalog = ToolCatalog::fromCompiledApi(TestApis::articles());

        $list = $catalog->get('listArticles');
        self::assertNotNull($list);
        self::assertSame(HttpMethod::GET, $list->method);
        self::assertSame('/articles', $list->path->value);
        self::assertNull($list->body);
        $filter = $list->parameter('filter');
        self::assertNotNull($filter);
        self::assertSame(ParameterLocation::query, $filter->in);
        self::assertSame(ParameterStyle::deepObject, $filter->effectiveStyle());
        self::assertSame(ParameterLocation::header, $list->parameter('Accept-Language')?->in);
        self::assertSame(ParameterStyle::form, $list->parameter('limit')?->effectiveStyle());
        self::assertTrue($list->parameter('limit')->effectiveExplode());

        $update = $catalog->get('updateArticle');
        self::assertNotNull($update);
        self::assertEquals(new ToolRequestBody('application/json', required: true, isObject: true), $update->body);
        self::assertSame(ParameterLocation::path, $update->parameter('articleId')?->in);
        self::assertTrue($update->parameter('articleId')->required);
    }

    public function testTheEffectiveSecurityIsTheOperationsOwnOrElseTheDocuments(): void
    {
        $catalog = ToolCatalog::fromCompiledApi(TestApis::articles());
        self::assertNull($catalog->get('getArticle')?->security);
        self::assertSame([['bearerAuth' => []]], $catalog->get('createArticle')?->security?->jsonSerialize());
        self::assertTrue($catalog->get('createArticle')->requiresAuthentication());
        self::assertTrue($catalog->get('whoAmI')?->security?->anonymousAccessAllowed);
        self::assertFalse($catalog->get('whoAmI')->requiresAuthentication());

        $secured = (new ApiCompiler())->compile(
            ApiDefinition::create(
                info: new InfoObject(title: 'Other', version: '1'),
                securitySchemes: SecuritySchemeOrReferenceObjectMap::create()->with('apiKey', SecuritySchemeObject::bearer()),
                security: SecurityRequirementObject::scheme('apiKey'),
            )->withOperationsFrom(OtherApi::class),
        );
        self::assertSame([['apiKey' => []]], ToolCatalog::fromCompiledApi($secured)->get('otherStatus')?->security?->jsonSerialize());
    }

    public function testAnOperationIdThatIsNoValidToolNameFailsLoudly(): void
    {
        $this->expectException(InvalidToolException::class);
        $this->expectExceptionMessage('"list things!"');

        ToolCatalog::fromCompiledApi(TestApis::compile(InvalidNameApi::class));
    }

    public function testAParameterNamedBodyCollidesWithTheRequestBody(): void
    {
        $this->expectException(InvalidToolException::class);
        $this->expectExceptionMessage('the query parameter and the request body are both named "body"');

        ToolCatalog::fromCompiledApi(TestApis::compile(BodyCollisionApi::class));
    }

    public function testMergingCatalogsWithTheSameToolNameFailsLoudly(): void
    {
        $articles = ToolCatalog::fromCompiledApi(TestApis::compile(ArticleApi::class));
        $other = ToolCatalog::fromCompiledApi(TestApis::compile(OtherApi::class));

        $this->expectException(ToolNameCollisionException::class);
        $this->expectExceptionMessage('two tools named "listArticles"');

        $articles->merge($other);
    }

    public function testCatalogsWithDistinctNamesMerge(): void
    {
        $articles = ToolCatalog::fromCompiledApi(TestApis::compile(ArticleApi::class));
        $admin = ToolCatalog::fromCompiledApi(TestApis::compile(AdminApi::class));

        $merged = $articles->merge($admin);

        self::assertSame([...$articles->names(), 'flushCaches'], $merged->names());
        self::assertSame($articles->skipped, $merged->skipped);
    }

    public function testACatalogSurvivesSerialization(): void
    {
        $catalog = ToolCatalog::fromCompiledApi(TestApis::articles());

        $restored = unserialize(serialize($catalog));

        self::assertInstanceOf(ToolCatalog::class, $restored);
        self::assertEquals($catalog->get('listArticles'), $restored->get('listArticles'));
    }
}
