<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

/**
 * The JSON request body of a tool's operation, which a client passes as the `body` argument.
 */
final readonly class ToolRequestBody
{
    /**
     * The argument — and input schema property — the body travels in.
     */
    public const ARGUMENT_NAME = 'body';

    public function __construct(
        /**
         * The media type the body is sent as — `application/json`, or a `+json` type the operation declares instead
         */
        public string $contentType,
        public bool $required,
        /**
         * Whether the body's schema describes a JSON object. An empty object and an empty list are the same PHP
         * value once a tool call's arguments are decoded, so this is how the invoker knows to send `{}`.
         */
        public bool $isObject,
    ) {}
}
