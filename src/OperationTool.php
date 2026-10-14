<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Neos\OpenApi\Mcp\Exception\InvalidToolException;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Neos\OpenApi\Support\HttpMethod;
use Neos\OpenApi\Support\RelativePath;

/**
 * One operation of an OpenAPI document, described as an MCP tool — plus everything needed to turn a call of that
 * tool back into the HTTP request the operation answers.
 *
 * Plain data, built once by {@see ToolCatalog} and cacheable: no closures, no services. The two halves — what a
 * client sees ({@see toMcpTool()}) and how a call is carried out ({@see OperationInvoker}) — come from the same
 * Operation Object, so a tool cannot advertise an argument the invoker does not know where to put.
 */
final readonly class OperationTool
{
    /**
     * What MCP clients accept as a tool name. The specification only says SHOULD, but clients enforce it, and a
     * tool they drop silently is worse than a server that refuses to start.
     *
     * @see https://modelcontextprotocol.io/specification/2025-11-25/server/tools#tool-names
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,128}$/';

    /**
     * @param array<string, mixed> $inputSchema a JSON Schema (2020-12) object, with `$defs` bundled in
     * @param array<string, mixed>|\stdClass|null $outputSchema a JSON Schema (2020-12) of the successful
     *                                                         response body, with `$defs` bundled in
     * @param list<ToolParameter> $parameters
     * @param list<string> $tags the operation's OpenAPI tags
     * @throws InvalidToolException if the name is not a valid MCP tool name
     */
    public function __construct(
        public string $name,
        public string|null $title,
        public string $description,
        public array $inputSchema,
        public array|\stdClass|null $outputSchema,
        public ToolAnnotations $annotations,
        public string $operationId,
        /**
         * The requirement the operation is effectively under: its own, or else the document's. `null` when the
         * operation needs no authentication at all.
         */
        public SecurityRequirementObject|null $security,
        public HttpMethod $method,
        public RelativePath $path,
        public array $parameters,
        public ToolRequestBody|null $body,
        public array $tags = [],
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidToolException(sprintf(
                'The tool name "%s" (operationId "%s") is not a valid MCP tool name: it has to match %s. '
                . 'To solve this, give the operation an operationId that does, e.g. #[Operation(…, operationId: \'%s\')].',
                $name,
                $operationId,
                self::NAME_PATTERN,
                substr((string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name), 0, 128) ?: 'myOperation',
            ), 1791460201);
        }
    }

    /**
     * The annotations MCP defines, derived from the HTTP method's own semantics (RFC 9110 §9.2): GET and HEAD are
     * safe, so read-only; PUT and DELETE are idempotent; only DELETE is destructive. An API's operations act on its
     * own data and nothing beyond it, so none of them is "open world".
     */
    public static function annotationsFor(HttpMethod $method): ToolAnnotations
    {
        return new ToolAnnotations(
            readOnlyHint: in_array($method, [HttpMethod::GET, HttpMethod::HEAD], true),
            destructiveHint: $method === HttpMethod::DELETE,
            idempotentHint: in_array($method, [HttpMethod::GET, HttpMethod::HEAD, HttpMethod::PUT, HttpMethod::DELETE], true),
            openWorldHint: false,
        );
    }

    /**
     * Whether a caller has to be authenticated to use this tool at all.
     */
    public function requiresAuthentication(): bool
    {
        return $this->security !== null && !$this->security->anonymousAccessAllowed;
    }

    public function parameter(string $name): ToolParameter|null
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->name === $name) {
                return $parameter;
            }
        }
        return null;
    }

    /**
     * The tool definition as the MCP SDK takes it.
     */
    public function toMcpTool(): Tool
    {
        return new Tool(
            name: $this->name,
            title: $this->title,
            inputSchema: $this->inputSchema,
            description: $this->description,
            annotations: $this->annotations,
            // the SDK types this as an array, but an empty schema has to stay an object to encode as `{}`
            outputSchema: $this->outputSchema instanceof \stdClass ? [] : $this->outputSchema,
        );
    }
}
