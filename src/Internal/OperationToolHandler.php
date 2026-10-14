<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Internal;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Neos\OpenApi\Mcp\OperationInvoker;
use Neos\OpenApi\Mcp\OperationTool;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Binds one tool to the invoker that carries it out and the MCP request it is called in.
 *
 * @internal
 */
final readonly class OperationToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private OperationInvoker $invoker,
        private OperationTool $tool,
        private ServerRequestInterface $mcpRequest,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        return $this->invoker->invoke($this->tool, $arguments, $this->mcpRequest);
    }
}
