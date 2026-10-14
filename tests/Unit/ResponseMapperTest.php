<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Mcp\Schema\Content\TextContent;
use Neos\OpenApi\Mcp\ResponseMapper;
use PHPUnit\Framework\TestCase;

final class ResponseMapperTest extends TestCase
{
    public function testAnEmptyJsonObjectStaysAnObject(): void
    {
        $result = (new ResponseMapper())->toResult(new Response(200, ['Content-Type' => 'application/json'], '{"a":{},"b":[]}'));

        self::assertSame('{"a":{},"b":[]}', json_encode($result->structuredContent));
        self::assertSame('{"a":{},"b":[]}', self::text($result->content[0]));
    }

    public function testAJsonMediaTypeWithParametersOrASuffixIsJson(): void
    {
        $mapper = new ResponseMapper();

        self::assertSame(1, $mapper->toResult(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '1'))->structuredContent);
        self::assertSame(2, $mapper->toResult(new Response(200, ['Content-Type' => 'application/vnd.api+json'], '2'))->structuredContent);
    }

    public function testANonJsonSuccessIsPassedOnAsText(): void
    {
        $result = (new ResponseMapper())->toResult(new Response(200, ['Content-Type' => 'text/plain'], 'hello'));

        self::assertFalse($result->isError);
        self::assertNull($result->structuredContent);
        self::assertSame('hello', self::text($result->content[0]));
    }

    public function testAJsonErrorThatIsNoProblemDocumentIsAToolErrorWithTheJson(): void
    {
        $result = (new ResponseMapper())->toResult(new Response(422, ['Content-Type' => 'application/json'], '{"error":"nope"}'));

        self::assertTrue($result->isError);
        self::assertSame("The operation failed with HTTP 422 Unprocessable Entity.\n\n{\"error\":\"nope\"}", self::text($result->content[0]));
        self::assertSame('nope', $result->structuredContent->error);
    }

    public function testAPlainErrorBodyIsIncluded(): void
    {
        $result = (new ResponseMapper())->toResult(new Response(502, ['Content-Type' => 'text/plain'], 'upstream down'));

        self::assertTrue($result->isError);
        self::assertSame("The operation failed with HTTP 502 Bad Gateway.\n\nupstream down", self::text($result->content[0]));
    }

    private static function text(mixed $content): string
    {
        self::assertInstanceOf(TextContent::class, $content);
        return (string) $content->text;
    }
}
