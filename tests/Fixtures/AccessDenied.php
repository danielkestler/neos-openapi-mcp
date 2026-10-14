<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

/**
 * An application exception, which a middleware maps to a 403 — the way the Flow app maps its own.
 */
final class AccessDenied extends \RuntimeException {}
