<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\EndToEnd;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Neos\OpenApi\Mcp\Exception\ToolNameCollisionException;
use Neos\OpenApi\Mcp\ExposedApi;
use Neos\OpenApi\Mcp\McpServer;
use Neos\OpenApi\Mcp\ServerInfo;
use Neos\OpenApi\Mcp\Tests\Fixtures\AccessDeniedMiddleware;
use Neos\OpenApi\Mcp\Tests\Fixtures\ArticleApi;
use Neos\OpenApi\Mcp\Tests\Fixtures\OtherApi;
use Neos\OpenApi\Mcp\Tests\Fixtures\TestApis;
use Neos\OpenApi\Mcp\Tests\Fixtures\TestAuthContextProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * JSON-RPC over HTTP through the SDK's transport, in the shape a 2026-07-28 client sends it: the version and
 * client capabilities in `params._meta`, mirrored by the `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name`
 * headers.
 */
final class McpServerTest extends TestCase
{
    private const VERSION = '2026-07-28';

    private ArticleApi $api;
    private int $id = 0;

    protected function setUp(): void
    {
        $this->api = new ArticleApi();
    }

    /**
     * @param list<\Psr\Http\Server\MiddlewareInterface>|null $transportMiddlewares
     */
    private function server(array|null $transportMiddlewares = null): McpServer
    {
        $compiled = TestApis::articles();
        $factory = new HttpFactory();
        return new McpServer(
            new ServerInfo('neos', '9.2.0', title: 'Neos', instructions: 'Manage the articles of the blog.'),
            [ExposedApi::fromCompiledApi($compiled, TestApis::handler($compiled, $this->api), pathPrefix: '/api')],
            $factory,
            $factory,
            $factory,
            [new AccessDeniedMiddleware()],
            new TestAuthContextProvider(),
            $transportMiddlewares,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function post(string $method, array $params = [], array $headers = [], McpServer|null $server = null): ResponseInterface
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::VERSION,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ];
        $headers += [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::VERSION,
            'Mcp-Method' => $method,
        ];
        if (isset($params['name']) && is_string($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params]);
        return ($server ?? $this->server())->handle(new ServerRequest('POST', 'http://localhost/mcp', $headers, $body));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rpcResult(ResponseInterface $response): array
    {
        $message = self::json($response);
        self::assertArrayHasKey('result', $message, (string) json_encode($message));
        self::assertSame(200, $response->getStatusCode());
        return $message['result'];
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function listedTools(array $headers = []): array
    {
        $result = self::rpcResult($this->post('tools/list', [], $headers));
        self::assertArrayNotHasKey('nextCursor', $result);
        return array_column($result['tools'], 'name');
    }

    public function testServerDiscoverAdvertisesTheStatelessRevisionAndToolsOnly(): void
    {
        $response = $this->post('server/discover');
        $result = self::rpcResult($response);

        self::assertSame([self::VERSION], $result['supportedVersions']);
        self::assertStringContainsString('"capabilities":{"tools":{}}', (string) $response->getBody());
        self::assertSame('Manage the articles of the blog.', $result['instructions']);
    }

    public function testToolsListShowsAnAnonymousCallerOnlyThePublicTools(): void
    {
        self::assertSame(['listArticles', 'getArticle', 'updateArticle', 'tagArticle', 'ping', 'whoAmI'], $this->listedTools());
    }

    public function testToolsListShowsAnAuthenticatedCallerTheirTools(): void
    {
        self::assertSame(
            ['listArticles', 'getArticle', 'createArticle', 'updateArticle', 'deleteArticle', 'tagArticle', 'ping', 'whoAmI'],
            $this->listedTools(['Authorization' => 'Bearer alice']),
        );
        self::assertContains('flushCaches', $this->listedTools(['Authorization' => 'Bearer admin']));
    }

    public function testAListedToolCarriesItsSchemasAndAnnotations(): void
    {
        $tools = self::rpcResult($this->post('tools/list', [], ['Authorization' => 'Bearer alice']))['tools'];
        $create = $tools[array_search('createArticle', array_column($tools, 'name'), true)];

        self::assertSame('Create an article', $create['title']);
        self::assertSame("Create an article\n\nPOST /articles", $create['description']);
        self::assertSame(['body'], $create['inputSchema']['required']);
        self::assertSame('#/$defs/NewArticle', $create['inputSchema']['properties']['body']['$ref']);
        self::assertSame('#/$defs/Article', $create['outputSchema']['$ref']);
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false], $create['annotations']);
    }

    public function testToolsCallAnswersWithStructuredContent(): void
    {
        $result = self::rpcResult($this->post('tools/call', [
            'name' => 'listArticles',
            'arguments' => ['limit' => 1, 'filter' => ['author' => 'Ada']],
        ]));

        self::assertFalse($result['isError']);
        $expected = [['id' => 'article-1', 'title' => 'Article 1', 'author' => ['name' => 'Ada'], 'published' => false]];
        self::assertSame($expected, $result['structuredContent']);
        self::assertSame([['type' => 'text', 'text' => (string) json_encode($expected)]], $result['content']);
        self::assertSame('Ada', $this->api->lastArguments['filter']->author->value);
    }

    public function testToolsCallCarriesTheCallersCredentials(): void
    {
        $result = self::rpcResult($this->post(
            'tools/call',
            ['name' => 'createArticle', 'arguments' => ['body' => ['title' => 'Hello']]],
            ['Authorization' => 'Bearer alice'],
        ));

        self::assertFalse($result['isError']);
        self::assertSame(['id' => 'new', 'title' => 'Hello', 'author' => ['name' => 'alice'], 'published' => false], $result['structuredContent']);
    }

    public function testAnOperationsProblemIsAToolError(): void
    {
        $result = self::rpcResult($this->post('tools/call', ['name' => 'getArticle', 'arguments' => ['articleId' => 'secret']]));

        self::assertTrue($result['isError']);
        self::assertSame('Forbidden (HTTP 403): You may not read this article', $result['content'][0]['text']);
        self::assertSame(403, $result['structuredContent']['status']);
    }

    public function testArgumentsAreValidatedAgainstTheInputSchemaBeforeTheOperationRuns(): void
    {
        $message = self::json($this->post('tools/call', ['name' => 'getArticle', 'arguments' => ['articleId' => 'a-1', 'typo' => 1]]));

        self::assertSame(-32602, $message['error']['code']);
        self::assertSame([], $this->api->lastArguments);
    }

    public function testAHiddenToolCannotBeCalled(): void
    {
        $message = self::json($this->post('tools/call', ['name' => 'deleteArticle', 'arguments' => ['articleId' => 'a-1']]));

        self::assertSame(-32602, $message['error']['code']);
        self::assertSame([], $this->api->lastArguments);
    }

    public function testTheInitializeHandshakeIsNotServed(): void
    {
        $body = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'old', 'version' => '1']],
        ]);

        $response = $this->server()->handle(new ServerRequest('POST', 'http://localhost/mcp', ['Content-Type' => 'application/json'], $body));

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', self::json($response));
        self::assertFalse($response->hasHeader('Mcp-Session-Id'));
    }

    public function testAnOlderRevisionIsToldWhichOneIsSupported(): void
    {
        $body = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-11-25', 'io.modelcontextprotocol/clientCapabilities' => new \stdClass()]],
        ]);

        $response = $this->server()->handle(new ServerRequest('POST', 'http://localhost/mcp', [
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2025-11-25',
            'Mcp-Method' => 'tools/list',
        ], $body));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString(self::VERSION, (string) $response->getBody());
    }

    public function testOnlyPostIsAccepted(): void
    {
        self::assertSame(405, $this->server()->handle(new ServerRequest('GET', 'http://localhost/mcp'))->getStatusCode());
    }

    public function testTheDefaultTransportMiddlewaresRejectAForeignOrigin(): void
    {
        $response = $this->post('server/discover', [], ['Origin' => 'https://evil.example']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testTheOriginPolicyIsConfigurable(): void
    {
        $server = $this->server([new DnsRebindingProtectionMiddleware(['neos.example.com'])]);

        self::assertSame(200, $this->post('server/discover', [], ['Origin' => 'https://neos.example.com'], $server)->getStatusCode());
        self::assertSame(403, $this->post('server/discover', [], ['Origin' => 'http://localhost'], $server)->getStatusCode());
    }

    public function testSeveralApisAreServedTogether(): void
    {
        $articles = TestApis::compile(ArticleApi::class);
        $other = TestApis::compile(OtherApi::class);
        $factory = new HttpFactory();
        $otherApi = ExposedApi::fromCompiledApi($other, TestApis::handler($other, new OtherApi()), excludeTags: []);
        $server = new McpServer(
            new ServerInfo('neos', '1'),
            [
                ExposedApi::fromCompiledApi($articles, TestApis::handler($articles, $this->api)),
                new ExposedApi($otherApi->catalog->filter(static fn($tool): bool => $tool->name !== 'listArticles'), $otherApi->requestHandler),
            ],
            $factory,
            $factory,
            $factory,
        );

        $result = self::rpcResult($this->post('tools/call', ['name' => 'otherStatus', 'arguments' => new \stdClass()], [], $server));

        self::assertSame('fine', $result['structuredContent']);
        self::assertContains('otherStatus', array_column(self::rpcResult($this->post('tools/list', [], [], $server))['tools'], 'name'));
    }

    public function testAToolNameCollisionAcrossApisFailsWhenTheServerIsBuilt(): void
    {
        $articles = TestApis::compile(ArticleApi::class);
        $other = TestApis::compile(OtherApi::class);
        $factory = new HttpFactory();

        $this->expectException(ToolNameCollisionException::class);

        new McpServer(
            new ServerInfo('neos', '1'),
            [
                ExposedApi::fromCompiledApi($articles, TestApis::handler($articles, $this->api)),
                ExposedApi::fromCompiledApi($other, TestApis::handler($other, new OtherApi())),
            ],
            $factory,
            $factory,
            $factory,
        );
    }
}
