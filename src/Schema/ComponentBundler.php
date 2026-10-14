<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Schema;

use Neos\OpenApi\Mcp\Exception\InvalidToolException;
use Neos\OpenApi\Spec\OpenApiObject;

/**
 * Makes a schema taken out of an OpenAPI document stand on its own.
 *
 * Inside the document, a schema points at `#/components/schemas/X`. A tool's input or output schema is published
 * on its own, where that pointer leads nowhere — so every such reference is rewritten to `#/$defs/X`, and exactly
 * the components it reaches (directly or through other components) are copied into the schema's `$defs`. Bundling
 * all 200 components into every tool would bloat each `tools/list` entry for nothing.
 *
 * The component schemas are converted once, on construction, so building a whole catalog converts each one once.
 *
 * @internal
 */
final readonly class ComponentBundler
{
    private const COMPONENT_PREFIX = '#/components/schemas/';
    private const DEFS_PREFIX = '#/$defs/';

    /**
     * @param array<string, mixed> $components component name => schema, already converted to PHP values
     */
    private function __construct(
        private array $components,
    ) {}

    public static function forDocument(OpenApiObject $document): self
    {
        $components = [];
        foreach ($document->components?->schemas ?? [] as $name => $schema) {
            $components[(string) $name] = JsonValue::fromSerializable($schema);
        }
        return new self($components);
    }

    /**
     * The schema with its component references pointing into its own `$defs`, and those `$defs` added.
     *
     * @param array<string, mixed>|\stdClass $schema converted by {@see JsonValue}
     * @return array<string, mixed>|\stdClass
     * @throws InvalidToolException if the schema refers to something other than a component schema, or to a
     *                              component the document does not have
     */
    public function bundle(array|\stdClass $schema, string $operationId): array|\stdClass
    {
        $pending = [];
        $schema = $this->rewrite($schema, $pending, $operationId);
        $definitions = [];
        while ($pending !== []) {
            $name = array_shift($pending);
            if (array_key_exists($name, $definitions)) {
                continue;
            }
            if (!array_key_exists($name, $this->components)) {
                throw new InvalidToolException(sprintf(
                    'The operation "%s" refers to the component schema "%s", which the document does not have',
                    $operationId,
                    $name,
                ), 1791460101);
            }
            // reserve the slot first, so a component referring to itself is not queued forever
            $definitions[$name] = null;
            $definitions[$name] = $this->rewrite($this->components[$name], $pending, $operationId);
        }
        if ($definitions === []) {
            return $schema;
        }
        if ($schema instanceof \stdClass) {
            // only an empty schema converts to a stdClass, and an empty schema refers to nothing
            throw new \LogicException('An empty schema cannot carry references', 1791460102);
        }
        $schema['$defs'] = $definitions;
        return $schema;
    }

    /**
     * Whether a schema describes a JSON object, following references and looking past a `null` alternative.
     *
     * What the invoker needs to know to send an empty argument as `{}` rather than `[]`: once decoded into PHP, an
     * empty JSON object and an empty list are the same value.
     *
     * @param array<string, mixed>|\stdClass $schema converted by {@see JsonValue}, *before* bundling
     */
    public function describesObject(array|\stdClass $schema, int $depth = 0): bool
    {
        if (!is_array($schema) || $depth > 16) {
            return false;
        }
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref) && str_starts_with($ref, self::COMPONENT_PREFIX)) {
            $target = $this->components[substr($ref, strlen(self::COMPONENT_PREFIX))] ?? null;
            return (is_array($target) || $target instanceof \stdClass) && $this->describesObject($target, $depth + 1);
        }
        $type = $schema['type'] ?? null;
        if ($type === 'object' || (is_array($type) && in_array('object', $type, true))) {
            return true;
        }
        foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $branch) {
                if ((is_array($branch) || $branch instanceof \stdClass) && $this->describesObject($branch, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param list<string> $pending component names found, appended to
     */
    private function rewrite(mixed $node, array &$pending, string $operationId): mixed
    {
        if ($node instanceof \stdClass) {
            $rewritten = new \stdClass();
            foreach (get_object_vars($node) as $name => $member) {
                $rewritten->{$name} = $this->rewrite($member, $pending, $operationId);
            }
            return $rewritten;
        }
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $key => $member) {
            if ($key === '$ref' && is_string($member)) {
                $node[$key] = $this->rewriteReference($member, $pending, $operationId);
                continue;
            }
            $node[$key] = $this->rewrite($member, $pending, $operationId);
        }
        return $node;
    }

    /**
     * @param list<string> $pending
     */
    private function rewriteReference(string $reference, array &$pending, string $operationId): string
    {
        if (!str_starts_with($reference, self::COMPONENT_PREFIX)) {
            throw new InvalidToolException(sprintf(
                'The operation "%s" uses the reference "%s"; only references to "%s…" can be bundled into a tool schema',
                $operationId,
                $reference,
                self::COMPONENT_PREFIX,
            ), 1791460103);
        }
        $name = substr($reference, strlen(self::COMPONENT_PREFIX));
        $pending[] = $name;
        return self::DEFS_PREFIX . $name;
    }
}
