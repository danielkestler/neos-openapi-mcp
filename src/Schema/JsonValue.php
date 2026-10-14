<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Schema;

/**
 * Turns the spec objects of neos/openapi — all of them `JsonSerializable` — into the plain PHP arrays the MCP SDK
 * takes a schema as.
 *
 * The one thing that makes this more than `json_decode(json_encode(…), true)`: an empty JSON object has to stay one.
 * Decoded into an associative array it becomes `[]`, which re-encodes as a JSON *array* — `"properties": []` or
 * `"default": []` where the document said `{}`. Every empty object is therefore kept as a `\stdClass`, and so is
 * the (rare) object whose keys happen to be `0…n`, which an array would otherwise turn into a list.
 *
 * @internal
 */
final class JsonValue
{
    public const ENCODING_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private function __construct() {}

    /**
     * @return mixed arrays for JSON objects and lists, `\stdClass` for the objects an array cannot represent
     */
    public static function fromSerializable(mixed $value): mixed
    {
        return self::normalize(json_decode(json_encode($value, self::ENCODING_FLAGS), false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Same as {@see fromSerializable()}, for a value that has to be a JSON object.
     *
     * @return array<string, mixed>|\stdClass
     */
    public static function objectFromSerializable(mixed $value): array|\stdClass
    {
        $converted = self::fromSerializable($value);
        if (!$converted instanceof \stdClass && !(is_array($converted) && !array_is_list($converted))) {
            throw new \UnexpectedValueException(sprintf('Expected a JSON object, got %s', get_debug_type($converted)), 1791460001);
        }
        return $converted;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = [];
            foreach (get_object_vars($value) as $name => $member) {
                $members[$name] = self::normalize($member);
            }
            // `[]` and `[0 => …, 1 => …]` would both encode as JSON arrays
            return $members === [] || array_is_list($members) ? (object) $members : $members;
        }
        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }
        return $value;
    }
}
