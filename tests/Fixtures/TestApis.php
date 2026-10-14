<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use GuzzleHttp\Psr7\HttpFactory;
use Neos\OpenApi\ApiDefinition;
use Neos\OpenApi\Compilation\ApiCompiler;
use Neos\OpenApi\Compilation\CompiledApi;
use Neos\OpenApi\Http\RequestHandler;
use Neos\OpenApi\Spec\InfoObject;
use Neos\OpenApi\Spec\SecuritySchemeObject;
use Neos\OpenApi\Spec\SecuritySchemeOrReferenceObjectMap;
use Neos\OpenApi\Support\FixedContainer;

/**
 * Compiles the fixture APIs the way an application would.
 */
final class TestApis
{
    /**
     * @param class-string ...$apiClasses
     */
    public static function compile(string ...$apiClasses): CompiledApi
    {
        $definition = ApiDefinition::create(
            info: new InfoObject(title: 'Articles', version: '1.0.0'),
            securitySchemes: SecuritySchemeOrReferenceObjectMap::create()
                ->with('bearerAuth', SecuritySchemeObject::bearer())
                ->with('adminAuth', SecuritySchemeObject::bearer()),
        );
        foreach ($apiClasses as $apiClass) {
            $definition = $definition->withOperationsFrom($apiClass);
        }
        return (new ApiCompiler())->compile($definition);
    }

    public static function articles(): CompiledApi
    {
        return self::compile(ArticleApi::class, AdminApi::class);
    }

    public static function handler(CompiledApi $api, object ...$apiInstances): RequestHandler
    {
        $factory = new HttpFactory();
        return new RequestHandler($api, new FixedContainer(...$apiInstances), $factory, $factory, new TestAuthContextProvider());
    }
}
