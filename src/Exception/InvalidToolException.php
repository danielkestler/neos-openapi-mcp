<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Exception;

/**
 * An operation of the document cannot be turned into an MCP tool — its operationId is not a valid tool name, one of
 * its inputs collides with another, or it uses a construct the tool schema cannot express.
 *
 * Thrown while the catalog is built rather than when a client calls the tool: these are mistakes in the API being
 * exposed, and a server that silently drops or mangles a tool is harder to debug than one that refuses to start.
 */
final class InvalidToolException extends \InvalidArgumentException {}
