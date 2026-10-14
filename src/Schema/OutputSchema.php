<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Schema;

use Neos\OpenApi\Spec\OperationObject;
use Neos\OpenApi\Spec\ResponseObject;
use Neos\OpenApi\Support\MediaTypeRange;

/**
 * Builds a tool's output schema: the schema of the operation's successful JSON response, which is exactly what
 * the invoker hands back as `structuredContent`.
 *
 * "Successful" is the first 2xx response with a JSON body, in status code order — the one a client should expect
 * in the normal case. An operation whose 2xx responses carry no JSON (a `204`, say) has no output schema, rather
 * than one that would promise structured content the call never returns.
 */
final class OutputSchema
{
    private function __construct() {}

    /**
     * @return array<string, mixed>|\stdClass|null
     */
    public static function build(string $operationId, OperationObject $operation, ComponentBundler $bundler): array|\stdClass|null
    {
        foreach ($operation->responses ?? [] as $statusCode => $response) {
            if (!is_numeric($statusCode) || (int) $statusCode < 200 || (int) $statusCode > 299) {
                continue;
            }
            // a reference to `components.responses` is something neos/openapi does not emit
            if (!$response instanceof ResponseObject || $response->content === null) {
                continue;
            }
            foreach ($response->content as $range => $mediaTypeObject) {
                if (!self::isJson((string) $range) || $mediaTypeObject->schema === null) {
                    continue;
                }
                return $bundler->bundle(JsonValue::objectFromSerializable($mediaTypeObject->schema), $operationId);
            }
        }
        return null;
    }

    public static function isJson(string $mediaTypeRange): bool
    {
        try {
            $range = MediaTypeRange::fromString($mediaTypeRange);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $subtype = strtolower(explode(';', $range->subtype, 2)[0]);
        return strtolower($range->type) === 'application' && ($subtype === 'json' || str_ends_with($subtype, '+json'));
    }
}
