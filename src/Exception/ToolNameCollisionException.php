<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Exception;

/**
 * Two tools claim the same name — typically two APIs exposed through one MCP server that both have an operation
 * with the same operationId.
 *
 * MCP addresses a tool by its name alone, so there is no way to tell the two apart once they are registered; the
 * second one would silently replace the first.
 */
final class ToolNameCollisionException extends \InvalidArgumentException {}
