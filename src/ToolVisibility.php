<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Neos\OpenApi\Http\AuthContextProvider;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides which tools a caller gets to see.
 *
 * A tool the caller cannot use is noise in a model's context at best, and an invitation to try at worst — so
 * `tools/list` shows only those whose security requirement the caller's credentials satisfy. The judgement is the
 * application's own: the same {@see AuthContextProvider} the request handler asks before running an operation, asked
 * with the MCP request (whose `Authorization` header is the one the tool call will carry).
 *
 * - no requirement, or one that allows anonymous access → visible to everyone;
 * - otherwise visible if the provider returns a caller, hidden if it returns `null` *or throws* — a provider that
 *   cannot make sense of the credentials has not vouched for them.
 *
 * This is about what is *listed*, not what is *allowed*: the operation still checks for itself when it is called,
 * so a provider that is more generous here than there costs nothing but a `401`.
 */
final readonly class ToolVisibility
{
    public function __construct(
        private AuthContextProvider $authContextProvider,
    ) {}

    public function isVisible(OperationTool $tool, ServerRequestInterface $request): bool
    {
        if ($tool->security === null || $tool->security->anonymousAccessAllowed) {
            return true;
        }
        return $this->satisfies($tool->security, $request);
    }

    /**
     * The tools of the catalog the caller may see.
     *
     * The provider is asked once per distinct requirement rather than once per tool: most operations of an API
     * share a handful of requirements, and validating the same token eighty times would be wasted work.
     */
    public function visibleTools(ToolCatalog $catalog, ServerRequestInterface $request): ToolCatalog
    {
        /** @var array<string, bool> $verdicts serialized requirement => satisfied */
        $verdicts = [];
        return $catalog->filter(function (OperationTool $tool) use ($request, &$verdicts): bool {
            if ($tool->security === null || $tool->security->anonymousAccessAllowed) {
                return true;
            }
            $key = (string) json_encode($tool->security);
            return $verdicts[$key] ??= $this->satisfies($tool->security, $request);
        });
    }

    private function satisfies(SecurityRequirementObject $requirement, ServerRequestInterface $request): bool
    {
        try {
            return $this->authContextProvider->authContextFor($request, $requirement) !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
