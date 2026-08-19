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

use KonradMichalik\Ttt\Http\Requests;
use KonradMichalik\Typo3Routing\Middleware\RouteDispatcher;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function str_contains;

/**
 * InheritedRoutesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class InheritedRoutesTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_routing',
        __DIR__.'/../Fixtures/Extensions/routing_test',
    ];

    #[Test]
    public function answersTheListRouteInheritedFromTheAbstractParent(): void
    {
        $response = $this->process($this->request('https://example.com/api/products'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"resource":"product","page":1}', (string) $response->getBody());
    }

    #[Test]
    public function appliesEachSubclassPrefixToTheSameInheritedMethod(): void
    {
        $products = $this->process($this->request('https://example.com/api/products/7'));
        $news = $this->process($this->request('https://example.com/api/news/7'));

        self::assertJsonStringEqualsJsonString('{"resource":"product","uid":7}', (string) $products->getBody());
        self::assertJsonStringEqualsJsonString('{"resource":"news","uid":7}', (string) $news->getBody());
    }

    #[Test]
    public function hoistsTheParamRequirementPerSubclass(): void
    {
        $response = $this->process($this->request('https://example.com/api/news', ['page' => 'abc']));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function registersFourRoutesAndNoneForTheAbstractParent(): void
    {
        $routes = $this->get(RouteRegistry::class)->getRoutes();

        foreach (['products_list', 'products_detail', 'news_list', 'news_detail'] as $name) {
            self::assertArrayHasKey($name, $routes);
        }

        foreach ($routes as $route) {
            self::assertFalse(str_contains($route['controller'], 'AbstractResourceController'));
        }
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        return $this->get(RouteDispatcher::class)->process($request, $this->handler());
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function request(string $url, array $queryParams = []): ServerRequest
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => 'https://example.com/',
                ],
            ],
        ]);

        return Requests::get($url)
            ->withQueryParams($queryParams)
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->build();
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://temp', 200);
            }
        };
    }
}
