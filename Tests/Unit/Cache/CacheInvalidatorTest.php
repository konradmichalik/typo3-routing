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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Cache;

use KonradMichalik\Typo3Routing\Cache\{CacheInvalidator, ResponseCacheManager};
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\CreatesResponseCacheManager;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Http\Response;

/**
 * CacheInvalidatorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CacheInvalidator::class)]
final class CacheInvalidatorTest extends TestCase
{
    use CreatesResponseCacheManager;

    private ResponseCacheManager $cache;
    private CacheInvalidator $subject;

    protected function setUp(): void
    {
        $this->cache = $this->createResponseCacheManager();
        $registry = new RouteRegistry([], new ServiceLocator([]), cacheInvalidations: ['news_create' => ['news', 'teaser']]);
        $this->subject = new CacheInvalidator($registry, $this->cache);

        $this->cache->store('news', new Response('php://temp', 200), 3600, ['news']);
        $this->cache->store('teaser', new Response('php://temp', 200), 3600, ['teaser']);
        $this->cache->store('other', new Response('php://temp', 200), 3600, ['other']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function flushingStatuses(): iterable
    {
        yield '200' => [200];
        yield '303 after POST-redirect-GET' => [303];
        yield '399' => [399];
    }

    #[Test]
    #[DataProvider('flushingStatuses')]
    public function flushesEveryDeclaredTagAfterASuccessOrRedirectResponse(int $status): void
    {
        $this->subject->afterResponse('news_create', $this->responseWithStatus($status));

        self::assertNull($this->cache->get('news'));
        self::assertNull($this->cache->get('teaser'));
        self::assertNotNull($this->cache->get('other'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonFlushingStatuses(): iterable
    {
        yield '199' => [199];
        yield '400' => [400];
        yield '500' => [500];
    }

    #[Test]
    #[DataProvider('nonFlushingStatuses')]
    public function flushesNothingAfterAnInformationalOrErrorResponse(int $status): void
    {
        $this->subject->afterResponse('news_create', $this->responseWithStatus($status));

        self::assertNotNull($this->cache->get('news'));
        self::assertNotNull($this->cache->get('teaser'));
    }

    #[Test]
    public function flushesNothingForARouteWithoutDeclaredTags(): void
    {
        $this->subject->afterResponse('other_route', new Response('php://temp', 200));

        self::assertNotNull($this->cache->get('news'));
        self::assertNotNull($this->cache->get('other'));
    }

    // TYPO3's Response rejects unregistered codes such as 199 and 399, which the boundaries need.
    private function responseWithStatus(int $status): ResponseInterface
    {
        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);

        return $response;
    }
}
