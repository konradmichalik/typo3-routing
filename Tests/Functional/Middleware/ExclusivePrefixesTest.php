<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_routing" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3Routing\Tests\Functional\Middleware;

use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Typo3Routing\Middleware\RouteDispatcher;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ExclusivePrefixesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ExclusivePrefixesTest extends FunctionalTestCase
{
    protected bool $initializeDatabase = false;

    protected array $testExtensionsToLoad = [
        'typo3_routing',
        __DIR__.'/../Fixtures/Extensions/routing_test',
    ];

    /**
     * Claiming `/api/` turns an unmatched path inside it into a JSON 404 instead of a fall-through. The
     * sibling RouteDispatcherTest deliberately runs without the setting, covering the default — and
     * thereby proving the gate derived from the route paths works on its own.
     */
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'typo3_routing' => [
                'exclusivePrefixes' => '/api/',
            ],
        ],
    ];

    #[Test]
    public function returnsJsonNotFoundForUnknownPathInsideTheClaimedPrefix(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Not Found","status":404}', (string) $response->getBody());
    }

    /**
     * The claim reaches paths the derived gate never covers: no route begins with `/api/nothing`, so
     * only the configured prefix can pull this path into the dispatcher at all.
     */
    #[Test]
    public function claimsPathsThatNoRoutePrefixWouldCover(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/nothing/here'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function rejectsRouteParameterViolatingRequirement(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/item/abc'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returnsNotFoundWhenSchemeDoesNotMatch(): void
    {
        $response = $this->process($this->request('GET', 'http://example.com/api/example/restricted'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returnsNotFoundWhenHostDoesNotMatch(): void
    {
        $response = $this->process($this->request('GET', 'https://other.example.com/api/example/restricted', 'https://other.example.com/'));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * The claim is scoped: a path outside it stays the page router's business even when unmatched.
     */
    #[Test]
    public function stillFallsThroughOutsideTheClaimedPrefix(): void
    {
        $sentinel = new Response('php://temp', 418);

        $response = $this->process($this->request('GET', 'https://example.com/some/page'), $sentinel);

        self::assertSame($sentinel, $response);
    }

    private function process(ServerRequestInterface $request, ?ResponseInterface $fallThrough = null): ResponseInterface
    {
        $dispatcher = $this->get(RouteDispatcher::class);

        return $dispatcher->process($request, $this->handler($fallThrough ?? new Response('php://temp', 200)));
    }

    private function request(string $method, string $url, string $base = 'https://example.com/'): ServerRequest
    {
        $site = new Site('main', 1, [
            'base' => $base,
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => $base,
                ],
            ],
        ]);

        return (new RequestBuilder($method, $url))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->build();
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
