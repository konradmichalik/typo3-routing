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

use KonradMichalik\Typo3Routing\Middleware\RouteDispatcher;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * EntityResolutionTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class EntityResolutionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['extbase'];

    protected array $testExtensionsToLoad = [
        'typo3_routing',
        __DIR__.'/../Fixtures/Extensions/routing_test',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Fixtures/item.csv');
    }

    #[Test]
    public function resolvesExtbaseDomainModelFromPathPlaceholder(): void
    {
        $response = $this->process($this->request('https://example.com/api/example/entity/1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"id":1,"title":"Findable Item"}', (string) $response->getBody());
    }

    #[Test]
    public function returnsNotFoundWhenNoRecordMatchesTheIdentifier(): void
    {
        $response = $this->process($this->request('https://example.com/api/example/entity/999'));

        self::assertSame(404, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Not Found","status":404}', (string) $response->getBody());
    }

    #[Test]
    public function returnsBadRequestWhenIdentifierIsNotShapedLikeAnId(): void
    {
        $response = $this->process($this->request('https://example.com/api/example/entity/abc'));

        self::assertSame(400, $response->getStatusCode());
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        $dispatcher = $this->get(RouteDispatcher::class);

        return $dispatcher->process($request, $this->handler());
    }

    private function request(string $url): ServerRequest
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

        return (new ServerRequest($url, 'GET'))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage());
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
