<?php

declare(strict_types=1);

namespace Neos\OpenApi\Mcp\Tests\Fixtures;

use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;
use Neos\OpenApi\Attributes\Parameter;
use Neos\OpenApi\Attributes\RequestBody;
use Neos\OpenApi\Support\ParameterLocation;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Every shape a tool has to handle: path, query, deepObject and header parameters, JSON bodies (one that may be
 * `{}`), a 201, a 204, a bodyless 404, a problem-producing 400 and 401, an application exception — plus the two
 * operations that cannot be tools.
 */
final class ArticleApi
{
    /**
     * What the last invocation was handed, so tests can assert the arguments rather than only the response.
     *
     * @var array<string, mixed>
     */
    public array $lastArguments = [];

    #[Operation(path: '/articles', method: 'GET', summary: 'List articles', description: 'The articles, newest first.')]
    public function listArticles(
        int $limit = 10,
        ArticleFilter|null $filter = null,
        #[Parameter(in: ParameterLocation::header, name: 'Accept-Language', description: 'The preferred language')]
        string|null $language = null,
    ): Articles {
        $this->lastArguments = ['limit' => $limit, 'filter' => $filter, 'language' => $language];
        $articles = [];
        for ($index = 1; $index <= $limit; $index++) {
            $articles[] = self::article('article-' . $index, 'Article ' . $index);
        }
        return new Articles(...$articles);
    }

    #[Operation(path: '/articles/{articleId}', method: 'GET', summary: 'Get an article')]
    public function getArticle(ArticleId $articleId): Article|ArticleNotFound
    {
        $this->lastArguments = ['articleId' => $articleId];
        return match ($articleId->value) {
            'missing' => new ArticleNotFound(),
            'secret' => throw new AccessDenied('You may not read this article'),
            default => self::article($articleId->value, 'The article'),
        };
    }

    #[Operation(path: '/articles', method: 'POST', summary: 'Create an article', security: 'bearerAuth')]
    public function createArticle(
        #[RequestBody(description: 'The article to create')]
        NewArticle $article,
        #[AuthContext]
        Caller $caller,
    ): ArticleCreated {
        $this->lastArguments = ['article' => $article, 'caller' => $caller];
        return new ArticleCreated(self::article('new', $article->title, $caller->name));
    }

    #[Operation(path: '/articles/{articleId}', method: 'PATCH', summary: 'Update an article')]
    public function updateArticle(
        ArticleId $articleId,
        #[RequestBody]
        ArticlePatch $patch,
    ): Article {
        $this->lastArguments = ['articleId' => $articleId, 'patch' => $patch];
        return self::article($articleId->value, $patch->title ?? 'Unchanged');
    }

    #[Operation(path: '/articles/{articleId}', method: 'DELETE', summary: 'Delete an article', security: 'bearerAuth')]
    public function deleteArticle(ArticleId $articleId): void
    {
        $this->lastArguments = ['articleId' => $articleId];
    }

    #[Operation(path: '/articles/{articleId}/tags/{tag}', method: 'PUT', summary: 'Tag an article')]
    public function tagArticle(ArticleId $articleId, string $tag): void
    {
        $this->lastArguments = ['articleId' => $articleId, 'tag' => $tag];
    }

    /**
     * Takes the raw request, as an operation building links from it would, and still is a tool
     */
    #[Operation(path: '/ping', method: 'GET', deprecated: true)]
    public function ping(ServerRequestInterface $request): string
    {
        $this->lastArguments = [];
        return 'pong';
    }

    #[Operation(path: '/whoami', method: 'GET', summary: 'Who am I', security: 'bearerAuth', allowAnonymous: true)]
    public function whoAmI(#[AuthContext] Caller|null $caller): string
    {
        return $caller?->name ?? 'nobody';
    }

    #[Operation(path: '/articles/{articleId}/attachments', method: 'POST', summary: 'Upload an attachment')]
    public function uploadAttachment(ArticleId $articleId, ServerRequestInterface $request): void {}

    #[Operation(path: '/imports', method: 'POST', summary: 'Import an article')]
    public function importArticle(#[RequestBody(contentType: 'multipart/form-data')] NewArticle $article): void {}

    private static function article(string $id, string $title, string $author = 'Ada'): Article
    {
        return new Article(ArticleId::fromString($id), $title, new Author(AuthorName::fromString($author)));
    }
}
