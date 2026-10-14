<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Neos\OpenApi\Mcp\OperationInvoker;
use Neos\OpenApi\Mcp\OperationTool;
use Neos\OpenApi\Mcp\ToolCatalog;
use Neos\OpenApi\Mcp\ToolParameter;
use Neos\OpenApi\Mcp\Tests\Fixtures\AccessDenied;
use Neos\OpenApi\Mcp\Tests\Fixtures\AccessDeniedMiddleware;
use Neos\OpenApi\Mcp\Tests\Fixtures\ArticleApi;
use Neos\OpenApi\Mcp\Tests\Fixtures\ArticleFilter;
use Neos\OpenApi\Mcp\Tests\Fixtures\ArticlePatch;
use Neos\OpenApi\Mcp\Tests\Fixtures\Caller;
use Neos\OpenApi\Mcp\Tests\Fixtures\NewArticle;
use Neos\OpenApi\Mcp\Tests\Fixtures\TestApis;
use Neos\OpenApi\Support\HttpMethod;
use Neos\OpenApi\Support\ParameterLocation;
use Neos\OpenApi\Support\ParameterStyle;
use Neos\OpenApi\Support\RelativePath;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OperationInvokerTest extends TestCase
{
    private ArticleApi $api;
    private ToolCatalog $catalog;
    private ServerRequest $mcpRequest;

    protected function setUp(): void
    {
        $this->api = new ArticleApi();
        $this->catalog = ToolCatalog::fromCompiledApi(TestApis::articles());
        $this->mcpRequest = new ServerRequest('POST', 'https://neos.example.com:8443/mcp', [
            'Authorization' => 'Bearer alice',
            'Cookie' => 'Neos_Session=secret',
            'User-Agent' => 'test-client/1.0',
            'Content-Type' => 'application/json',
        ], serverParams: ['REMOTE_ADDR' => '192.0.2.1']);
    }

    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    private function invoker(array $middlewares = [], string $pathPrefix = ''): OperationInvoker
    {
        $factory = new HttpFactory();
        return new OperationInvoker(
            TestApis::handler(TestApis::articles(), $this->api),
            $factory,
            $factory,
            $middlewares,
            $pathPrefix,
        );
    }

    private function tool(string $name): OperationTool
    {
        $tool = $this->catalog->get($name);
        self::assertNotNull($tool);
        return $tool;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function call(string $name, array $arguments, MiddlewareInterface ...$middlewares): CallToolResult
    {
        return $this->invoker(array_values($middlewares))->invoke($this->tool($name), $arguments, $this->mcpRequest);
    }

    private static function text(CallToolResult $result): string
    {
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        return (string) $result->content[0]->text;
    }

    // --- building the request ---

    public function testPathVariablesArePercentEncodedInThePathAndHandedOverDecoded(): void
    {
        $built = $this->invoker(pathPrefix: '/api/')->buildRequest(
            $this->tool('tagArticle'),
            ['articleId' => 'a-1', 'tag' => 'x y/z'],
            $this->mcpRequest,
        );

        self::assertSame('PUT', $built->request->getMethod());
        self::assertSame('/api/articles/a-1/tags/x%20y%2Fz', $built->request->getUri()->getPath());
        self::assertSame(['articleId' => 'a-1', 'tag' => 'x y/z'], $built->pathVariables);
        self::assertSame('tagArticle', $built->operationId);
    }

    public function testTheOriginServerParamsAndForwardedHeadersAreCarriedOverButCookiesAreNot(): void
    {
        $request = $this->invoker()->buildRequest($this->tool('ping'), [], $this->mcpRequest)->request;

        self::assertSame('https://neos.example.com:8443/ping', (string) $request->getUri());
        self::assertSame('Bearer alice', $request->getHeaderLine('Authorization'));
        self::assertSame('test-client/1.0', $request->getHeaderLine('User-Agent'));
        self::assertFalse($request->hasHeader('Cookie'));
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertSame(['REMOTE_ADDR' => '192.0.2.1'], $request->getServerParams());
        self::assertSame('', (string) $request->getBody());
    }

    public function testQueryParametersAreSerializedInTheirStyleAndSetAsQueryParams(): void
    {
        $request = $this->invoker()->buildRequest(
            $this->tool('listArticles'),
            ['limit' => 5, 'filter' => ['author' => 'Ada Lovelace', 'published' => true], 'Accept-Language' => 'de'],
            $this->mcpRequest,
        )->request;

        self::assertSame('limit=5&filter%5Bauthor%5D=Ada%20Lovelace&filter%5Bpublished%5D=true', $request->getUri()->getQuery());
        self::assertSame(['limit' => '5', 'filter' => ['author' => 'Ada Lovelace', 'published' => 'true']], $request->getQueryParams());
        self::assertSame('de', $request->getHeaderLine('Accept-Language'));
    }

    public function testAbsentAndNullArgumentsAreLeftOut(): void
    {
        $request = $this->invoker()->buildRequest(
            $this->tool('listArticles'),
            ['limit' => null, 'filter' => ['author' => null, 'published' => false]],
            $this->mcpRequest,
        )->request;

        self::assertSame('filter%5Bpublished%5D=false', $request->getUri()->getQuery());
        self::assertFalse($request->hasHeader('Accept-Language'));
    }

    public function testListsExplodeInFormStyleAndNestInDeepObjectStyle(): void
    {
        $tool = new OperationTool(
            name: 'search',
            title: null,
            description: 'GET /search',
            inputSchema: ['type' => 'object'],
            outputSchema: null,
            annotations: OperationTool::annotationsFor(HttpMethod::GET),
            operationId: 'search',
            security: null,
            method: HttpMethod::GET,
            path: RelativePath::fromString('/search'),
            parameters: [
                new ToolParameter('ids', ParameterLocation::query, false),
                new ToolParameter('tags', ParameterLocation::query, false, explode: false),
                new ToolParameter('arguments', ParameterLocation::query, false, ParameterStyle::deepObject, true),
                new ToolParameter('X-Ids', ParameterLocation::header, false),
                new ToolParameter('session', ParameterLocation::cookie, false),
            ],
            body: null,
        );

        $request = $this->invoker()->buildRequest($tool, [
            'ids' => [1, 2],
            'tags' => ['a', 'b'],
            'arguments' => ['path' => 'main', 'nodes' => ['n1', 'n2']],
            'X-Ids' => ['x', 'y'],
            'session' => 'a b',
        ], $this->mcpRequest)->request;

        self::assertSame(
            'ids=1&ids=2&tags=a%2Cb&arguments%5Bpath%5D=main&arguments%5Bnodes%5D%5B%5D=n1&arguments%5Bnodes%5D%5B%5D=n2',
            $request->getUri()->getQuery(),
        );
        self::assertSame(
            ['ids' => ['1', '2'], 'tags' => 'a,b', 'arguments' => ['path' => 'main', 'nodes' => ['n1', 'n2']]],
            $request->getQueryParams(),
        );
        self::assertSame('x,y', $request->getHeaderLine('X-Ids'));
        self::assertSame('session=a%20b', $request->getHeaderLine('Cookie'));
        self::assertSame(['session' => 'a b'], $request->getCookieParams());
    }

    public function testTheBodyIsSentAsJson(): void
    {
        $request = $this->invoker()->buildRequest(
            $this->tool('createArticle'),
            ['body' => ['title' => 'Hello / World', 'author' => 'Ünal']],
            $this->mcpRequest,
        )->request;

        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"title":"Hello / World","author":"Ünal"}', (string) $request->getBody());
    }

    public function testAnEmptyObjectBodyIsSentAsAnObject(): void
    {
        $request = $this->invoker()->buildRequest($this->tool('updateArticle'), ['articleId' => 'a-1', 'body' => []], $this->mcpRequest)->request;

        self::assertSame('{}', (string) $request->getBody());
    }

    // --- calling the operation ---

    public function testAJsonResponseBecomesStructuredContentAndText(): void
    {
        $result = $this->call('getArticle', ['articleId' => 'a-1']);

        self::assertFalse($result->isError);
        $expected = ['id' => 'a-1', 'title' => 'The article', 'author' => ['name' => 'Ada'], 'published' => false];
        self::assertSame($expected, json_decode((string) json_encode($result->structuredContent), true));
        self::assertSame($expected, json_decode(self::text($result), true));
    }

    public function testTheOperationReceivesTheArgumentsAsOverHttp(): void
    {
        $result = $this->call('listArticles', ['limit' => 2, 'filter' => ['author' => 'Ada', 'published' => true], 'Accept-Language' => 'de']);

        self::assertFalse($result->isError, self::text($result));
        self::assertSame(2, $this->api->lastArguments['limit']);
        self::assertSame('de', $this->api->lastArguments['language']);
        $filter = $this->api->lastArguments['filter'];
        self::assertInstanceOf(ArticleFilter::class, $filter);
        self::assertSame('Ada', $filter->author?->value);
        self::assertTrue($filter->published);
        self::assertIsArray($result->structuredContent);
        self::assertCount(2, $result->structuredContent);
    }

    public function testTheCallersCredentialsReachTheAuthContextProvider(): void
    {
        $result = $this->call('createArticle', ['body' => ['title' => 'Hello']]);

        self::assertFalse($result->isError, self::text($result));
        self::assertEquals(new Caller('alice'), $this->api->lastArguments['caller']);
        self::assertEquals(new NewArticle('Hello'), $this->api->lastArguments['article']);
        self::assertSame('new', $result->structuredContent->id);
    }

    public function testAnEmptyBodyArrivesAsAnEmptyObject(): void
    {
        $result = $this->call('updateArticle', ['articleId' => 'a-1', 'body' => []]);

        self::assertFalse($result->isError, self::text($result));
        self::assertEquals(new ArticlePatch(), $this->api->lastArguments['patch']);
    }

    public function testANoContentResponseIsASentence(): void
    {
        $result = $this->call('deleteArticle', ['articleId' => 'a-1']);

        self::assertFalse($result->isError);
        self::assertNull($result->structuredContent);
        self::assertSame('The operation succeeded (HTTP 204 No Content) and returned no content.', self::text($result));
    }

    public function testAScalarResultIsStructuredContentToo(): void
    {
        $result = $this->call('ping', []);

        self::assertSame('pong', $result->structuredContent);
        self::assertSame('"pong"', self::text($result));
    }

    public function testAProblemDocumentIsAToolErrorListingItsIssues(): void
    {
        $result = $this->call('createArticle', ['body' => ['title' => '']]);

        self::assertTrue($result->isError);
        $text = self::text($result);
        self::assertStringStartsWith('Bad Request (HTTP 400): The request could not be understood', $text);
        self::assertStringContainsString("\n- /body/title: ", $text);
        self::assertInstanceOf(\stdClass::class, $result->structuredContent);
        self::assertSame(400, $result->structuredContent->status);
    }

    public function testAMissingCredentialIsAToolError(): void
    {
        $this->mcpRequest = $this->mcpRequest->withoutHeader('Authorization');

        $result = $this->call('createArticle', ['body' => ['title' => 'Hello']]);

        self::assertTrue($result->isError);
        self::assertStringStartsWith('Unauthorized (HTTP 401)', self::text($result));
    }

    public function testAnErrorWithoutABodyIsAToolErrorNamingTheStatus(): void
    {
        $result = $this->call('getArticle', ['articleId' => 'missing']);

        self::assertTrue($result->isError);
        self::assertSame('The operation failed with HTTP 404 Not Found.', self::text($result));
    }

    public function testMiddlewaresWrapTheOperationAndCanMapItsExceptions(): void
    {
        $middleware = new AccessDeniedMiddleware();

        $result = $this->call('getArticle', ['articleId' => 'secret'], $middleware);

        self::assertTrue($result->isError);
        self::assertSame('Forbidden (HTTP 403): You may not read this article', self::text($result));
        self::assertCount(1, $middleware->seen);
        self::assertSame('/articles/secret', $middleware->seen[0]->getUri()->getPath());
    }

    public function testMiddlewaresRunFirstOneOutermost(): void
    {
        $order = new \ArrayObject();
        $recorder = static fn(string $name): MiddlewareInterface => new class ($name, $order) implements MiddlewareInterface {
            /** @param \ArrayObject<int, string> $order */
            public function __construct(private string $name, private \ArrayObject $order) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->order[] = $this->name . ':in';
                $response = $handler->handle($request);
                $this->order[] = $this->name . ':out';
                return $response;
            }
        };

        $this->call('ping', [], $recorder('outer'), $recorder('inner'));

        self::assertSame(['outer:in', 'inner:in', 'inner:out', 'outer:out'], $order->getArrayCopy());
    }

    public function testAnExceptionNoMiddlewareHandlesPropagates(): void
    {
        $this->expectException(AccessDenied::class);

        $this->call('getArticle', ['articleId' => 'secret']);
    }

    public function testAnArgumentThatCannotBeSerializedIsAToolError(): void
    {
        $result = $this->call('tagArticle', ['articleId' => 'a-1', 'tag' => ['nested' => ['too' => 'deep']]]);

        self::assertTrue($result->isError);
        self::assertSame('The argument "tag" has a array where a single value is expected', self::text($result));
    }
}
