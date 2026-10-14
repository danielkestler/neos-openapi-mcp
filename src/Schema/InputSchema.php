<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Schema;

use Neos\OpenApi\Mcp\Exception\InvalidToolException;
use Neos\OpenApi\Mcp\ToolRequestBody;
use Neos\OpenApi\Spec\ParameterObject;
use Neos\OpenApi\Spec\RequestBodyObject;
use Neos\OpenApi\Support\ParameterLocation;

/**
 * Builds a tool's input schema from an operation's parameters and JSON request body.
 *
 * One flat object, because that is the only shape a tool's arguments can have: every parameter — path, query,
 * header, cookie — is a property under its own name, and the body is the property `body`. Where a value goes is
 * the invoker's business, not the client's; nesting them by location would only make every call longer.
 *
 * `additionalProperties: false` at the top, so a misspelt argument is rejected by the SDK's validation instead of
 * being dropped on the floor — the operation would otherwise run without it and look like it ignored the caller.
 */
final class InputSchema
{
    private function __construct() {}

    /**
     * @param list<ParameterObject> $parameters
     * @return array<string, mixed>
     * @throws InvalidToolException if two inputs would share a property name
     */
    public static function build(
        string $operationId,
        array $parameters,
        RequestBodyObject|null $requestBody,
        string|null $bodyMediaType,
        ComponentBundler $bundler,
    ): array {
        $properties = [];
        $required = [];
        $origins = [];
        foreach ($parameters as $parameter) {
            self::claim($operationId, $parameter->name, sprintf('the %s parameter', $parameter->in->value), $origins);
            $properties[$parameter->name] = self::parameterSchema($parameter);
            if ($parameter->in === ParameterLocation::path || $parameter->required === true) {
                $required[] = $parameter->name;
            }
        }

        if ($requestBody !== null && $bodyMediaType !== null) {
            self::claim($operationId, ToolRequestBody::ARGUMENT_NAME, 'the request body', $origins);
            $properties[ToolRequestBody::ARGUMENT_NAME] = self::bodySchema($requestBody, $bodyMediaType);
            if ($requestBody->required === true) {
                $required[] = ToolRequestBody::ARGUMENT_NAME;
            }
        }

        $schema = ['type' => 'object'];
        if ($properties !== []) {
            $schema['properties'] = $properties;
        }
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;

        $bundled = $bundler->bundle($schema, $operationId);
        if (!is_array($bundled)) {
            throw new \LogicException('A non-empty schema cannot bundle into an empty one', 1791460301);
        }
        return $bundled;
    }

    /**
     * @param array<string, string> $origins property name => what claimed it
     */
    private static function claim(string $operationId, string $name, string $what, array &$origins): void
    {
        if (isset($origins[$name])) {
            throw new InvalidToolException(sprintf(
                'The operation "%s" cannot be a tool: %s and %s are both named "%s", and a tool\'s arguments are '
                . 'one flat object. To solve this, rename one of them, e.g. with #[Parameter(in: …, name: …)].',
                $operationId,
                $origins[$name],
                $what,
                $name,
            ), 1791460302);
        }
        $origins[$name] = $what;
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    private static function parameterSchema(ParameterObject $parameter): array|\stdClass
    {
        $schema = $parameter->schema === null ? [] : JsonValue::objectFromSerializable($parameter->schema);
        if ($schema instanceof \stdClass) {
            $schema = [];
        }
        // the parameter's description says what it is *for* in this operation, which beats the type's generic one
        if ($parameter->description !== null && $parameter->description !== '') {
            $schema['description'] = $parameter->description;
        }
        if ($parameter->deprecated === true) {
            $schema['deprecated'] = true;
        }
        return $schema === [] ? new \stdClass() : $schema;
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    private static function bodySchema(RequestBodyObject $requestBody, string $mediaType): array|\stdClass
    {
        $mediaTypeObject = null;
        foreach ($requestBody->content as $range => $object) {
            if ($range === $mediaType) {
                $mediaTypeObject = $object;
            }
        }
        $schema = $mediaTypeObject?->schema === null ? [] : JsonValue::objectFromSerializable($mediaTypeObject->schema);
        if ($schema instanceof \stdClass) {
            $schema = [];
        }
        if ($requestBody->description !== null && $requestBody->description !== '') {
            $schema['description'] = $requestBody->description;
        }
        return $schema === [] ? new \stdClass() : $schema;
    }
}
