<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Unit;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\OpenApi\Mcp\ToolCatalog;
use Neos\OpenApi\Mcp\ToolVisibility;
use Neos\OpenApi\Mcp\Tests\Fixtures\TestApis;
use Neos\OpenApi\Mcp\Tests\Fixtures\TestAuthContextProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolVisibilityTest extends TestCase
{
    private const PUBLIC_TOOLS = ['listArticles', 'getArticle', 'updateArticle', 'tagArticle', 'ping', 'whoAmI'];

    /**
     * @return iterable<string, array{string|null, list<string>}>
     */
    public static function callers(): iterable
    {
        yield 'anonymous' => [null, self::PUBLIC_TOOLS];
        yield 'a user' => ['Bearer alice', [...self::PUBLIC_TOOLS, 'createArticle', 'deleteArticle']];
        yield 'an admin' => ['Bearer admin', [...self::PUBLIC_TOOLS, 'createArticle', 'deleteArticle', 'flushCaches']];
        yield 'a provider that throws' => ['Bearer broken', self::PUBLIC_TOOLS];
        yield 'credentials of another kind' => ['Basic YTpi', self::PUBLIC_TOOLS];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('callers')]
    public function testACallerSeesTheToolsTheirCredentialsSatisfy(string|null $authorization, array $expected): void
    {
        $request = new ServerRequest('POST', 'https://neos.example.com/mcp', $authorization === null ? [] : ['Authorization' => $authorization]);
        $catalog = ToolCatalog::fromCompiledApi(TestApis::articles());

        $visible = (new ToolVisibility(new TestAuthContextProvider()))->visibleTools($catalog, $request);

        self::assertEqualsCanonicalizing($expected, $visible->names());
    }

    public function testTheProviderIsAskedOncePerDistinctRequirement(): void
    {
        $provider = new TestAuthContextProvider();
        $request = new ServerRequest('POST', 'https://neos.example.com/mcp', ['Authorization' => 'Bearer alice']);

        (new ToolVisibility($provider))->visibleTools(ToolCatalog::fromCompiledApi(TestApis::articles()), $request);

        // createArticle and deleteArticle share bearerAuth; flushCaches has adminAuth; whoAmI allows anonymous access
        self::assertSame(2, $provider->calls);
    }

    public function testASingleToolCanBeJudged(): void
    {
        $catalog = ToolCatalog::fromCompiledApi(TestApis::articles());
        $visibility = new ToolVisibility(new TestAuthContextProvider());
        $anonymous = new ServerRequest('POST', 'https://neos.example.com/mcp');

        self::assertTrue($visibility->isVisible($catalog->get('getArticle') ?? self::fail(), $anonymous));
        self::assertTrue($visibility->isVisible($catalog->get('whoAmI') ?? self::fail(), $anonymous));
        self::assertFalse($visibility->isVisible($catalog->get('createArticle') ?? self::fail(), $anonymous));
        self::assertTrue($visibility->isVisible($catalog->get('createArticle') ?? self::fail(), $anonymous->withHeader('Authorization', 'Bearer bob')));
    }
}
