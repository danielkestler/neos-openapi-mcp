<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Neos\OpenApi\Mcp\Schema\JsonValue;
use Neos\OpenApi\Mcp\Schema\OutputSchema;
use Psr\Http\Message\ResponseInterface;

/**
 * Turns the HTTP response an operation answered with into the result of a tool call.
 *
 * - A **2xx with a JSON body** becomes `structuredContent` — what the tool's output schema describes — plus the
 *   same JSON as text, for clients that only read `content`.
 * - A **2xx without a body** (a `204`, typically) becomes a short sentence saying so: an empty `content` would leave
 *   a model unsure whether anything happened.
 * - **Anything else** is a tool error (`isError: true`), *not* a JSON-RPC error: MCP wants failures the model can
 *   see and correct, and "the title is required" is exactly that. A problem document (RFC 9457) is spelled out —
 *   title, detail and every issue with where it is — and passed along as `structuredContent` as well.
 *
 * JSON is decoded into objects rather than arrays, so an empty object in the response stays `{}` on the way out.
 */
final readonly class ResponseMapper
{
    public const PROBLEM_CONTENT_TYPE = 'application/problem+json';

    public function toResult(ResponseInterface $response): CallToolResult
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $mediaType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        $decoded = $body !== '' && $mediaType !== '' && OutputSchema::isJson($mediaType) ? self::decode($body) : null;

        if ($status >= 200 && $status < 300) {
            if (trim($body) === '') {
                return new CallToolResult([new TextContent(sprintf(
                    'The operation succeeded (HTTP %d %s) and returned no content.',
                    $status,
                    $response->getReasonPhrase(),
                ))]);
            }
            if ($decoded === null) {
                return new CallToolResult([new TextContent($body)]);
            }
            return new CallToolResult(
                [new TextContent(json_encode($decoded['value'], JsonValue::ENCODING_FLAGS))],
                structuredContent: $decoded['value'],
            );
        }

        if ($decoded !== null && $mediaType === self::PROBLEM_CONTENT_TYPE && $decoded['value'] instanceof \stdClass) {
            return new CallToolResult(
                [new TextContent(self::describeProblem($status, $decoded['value']))],
                isError: true,
                structuredContent: $decoded['value'],
            );
        }

        $summary = sprintf('The operation failed with HTTP %d %s.', $status, $response->getReasonPhrase());
        if ($decoded !== null) {
            return new CallToolResult(
                [new TextContent($summary . "\n\n" . json_encode($decoded['value'], JsonValue::ENCODING_FLAGS))],
                isError: true,
                structuredContent: $decoded['value'],
            );
        }
        return new CallToolResult(
            [new TextContent(trim($body) === '' ? $summary : $summary . "\n\n" . $body)],
            isError: true,
        );
    }

    /**
     * @return array{value: mixed}|null wrapped, because `null` is a perfectly good JSON document
     */
    private static function decode(string $body): array|null
    {
        try {
            return ['value' => json_decode($body, false, 512, JSON_THROW_ON_ERROR)];
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * "Bad Request (HTTP 400): The request could not be understood", followed by one line per issue, so a model
     * can see which argument to fix without parsing anything.
     */
    private static function describeProblem(int $status, \stdClass $problem): string
    {
        $title = is_string($problem->title ?? null) ? $problem->title : 'Error';
        $text = sprintf('%s (HTTP %d)', $title, is_int($problem->status ?? null) ? $problem->status : $status);
        if (is_string($problem->detail ?? null) && $problem->detail !== '') {
            $text .= ': ' . $problem->detail;
        }
        $issues = is_array($problem->issues ?? null) ? $problem->issues : [];
        foreach ($issues as $issue) {
            if (!$issue instanceof \stdClass) {
                continue;
            }
            $message = is_string($issue->message ?? null) ? $issue->message : 'invalid';
            $pointer = is_string($issue->pointer ?? null) ? $issue->pointer : '';
            $text .= "\n- " . ($pointer === '' ? $message : $pointer . ': ' . $message);
        }
        return $text;
    }
}
