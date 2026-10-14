<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp;

use Neos\OpenApi\Support\ParameterLocation;
use Neos\OpenApi\Support\ParameterStyle;

/**
 * Where one argument of a tool goes when the tool is called: which part of the HTTP request, under what name, and
 * how it is serialized there.
 *
 * The argument's *name* is the parameter's name, which is also its property name in the tool's input schema — so a
 * header parameter is the argument `Accept-Language`, not some camel-cased variant a client would have to guess.
 */
final readonly class ToolParameter
{
    public function __construct(
        public string $name,
        public ParameterLocation $in,
        public bool $required,
        /**
         * As declared; `null` means the location's default (`simple` for path and header, `form` for query and
         * cookie)
         */
        public ParameterStyle|null $style = null,
        /**
         * As declared; `null` means the style's default (`true` for `form`, `false` otherwise)
         */
        public bool|null $explode = null,
    ) {}

    public function effectiveStyle(): ParameterStyle
    {
        return $this->style ?? match ($this->in) {
            ParameterLocation::path, ParameterLocation::header => ParameterStyle::simple,
            ParameterLocation::query, ParameterLocation::cookie => ParameterStyle::form,
        };
    }

    public function effectiveExplode(): bool
    {
        return $this->explode ?? $this->effectiveStyle() === ParameterStyle::form;
    }
}
