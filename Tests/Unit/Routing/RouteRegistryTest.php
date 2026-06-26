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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Routing;

use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;

/**
 * RouteRegistryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteRegistry::class)]
final class RouteRegistryTest extends TestCase
{
    #[Test]
    public function buildsRouteCollectionWithDefaultsAndMethods(): void
    {
        $collection = $this->createRegistry()->getRouteCollection();
        $route = $collection->get('fixture_count');

        self::assertNotNull($route);
        self::assertSame('/api/count', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
        self::assertSame('fixture_controller::count', $route->getDefault('_controller'));
        self::assertNull($route->getDefault('_env'));
        self::assertSame('Development', $collection->get('fixture_submit')?->getDefault('_env'));
    }

    #[Test]
    public function matcherResolvesAKnownPath(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $match = $this->createRegistry()->getMatcher($context)->match('/api/count');

        self::assertSame('fixture_controller::count', $match['_controller']);
        self::assertSame('fixture_count', $match['_route']);
    }

    #[Test]
    public function matcherThrowsForUnknownPath(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $this->expectException(ResourceNotFoundException::class);

        $this->createRegistry()->getMatcher($context)->match('/api/unknown');
    }

    #[Test]
    public function matcherThrowsForDisallowedMethod(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $this->expectException(MethodNotAllowedException::class);

        $this->createRegistry()->getMatcher($context)->match('/api/submit');
    }

    #[Test]
    public function matcherMatchesPathSatisfyingRequirement(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $match = $this->constrainedRegistry()->getMatcher($context)->match('/api/item/42');

        self::assertSame('fixture_controller::item', $match['_controller']);
        self::assertSame('42', $match['id']);
    }

    #[Test]
    public function matcherRejectsPathViolatingRequirement(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $this->expectException(ResourceNotFoundException::class);

        $this->constrainedRegistry()->getMatcher($context)->match('/api/item/abc');
    }

    #[Test]
    public function exposesCacheConfigPerRouteName(): void
    {
        $registry = new RouteRegistry(
            [],
            new ServiceLocator([]),
            ['cached' => ['lifetime' => 60, 'tags' => ['pages'], 'ignoreParams' => ['search']]],
        );

        $config = $registry->getCacheConfig('cached');

        self::assertNotNull($config);
        self::assertSame(60, $config['lifetime']);
        self::assertSame(['pages'], $config['tags']);
        self::assertNull($registry->getCacheConfig('uncached'));
    }

    #[Test]
    public function exposesRateLimitPerRouteName(): void
    {
        $registry = new RouteRegistry(
            [],
            new ServiceLocator([]),
            [],
            ['limited' => ['limit' => 60, 'interval' => '1 minute', 'policy' => 'sliding_window']],
        );

        $rateLimit = $registry->getRateLimit('limited');

        self::assertNotNull($rateLimit);
        self::assertSame(60, $rateLimit['limit']);
        self::assertSame('1 minute', $rateLimit['interval']);
        self::assertSame('sliding_window', $rateLimit['policy']);
        self::assertNull($registry->getRateLimit('unlimited'));
    }

    #[Test]
    public function exposesRawRoutesAndControllerLocator(): void
    {
        $locator = new ServiceLocator([]);
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['x' => ['path' => '/x', 'methods' => ['GET'], 'controller' => 'a::b', 'env' => null, 'requirements' => []]];
        $registry = new RouteRegistry($routes, $locator);

        self::assertArrayHasKey('x', $registry->getRoutes());
        self::assertSame($locator, $registry->getControllerLocator());
    }

    private function createRegistry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'fixture_count' => [
                'path' => '/api/count',
                'methods' => ['GET'],
                'controller' => 'fixture_controller::count',
                'env' => null,
                'requirements' => [],
            ],
            'fixture_submit' => [
                'path' => '/api/submit',
                'methods' => ['POST'],
                'controller' => 'fixture_controller::submit',
                'env' => 'Development',
                'requirements' => [],
            ],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }

    private function constrainedRegistry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'fixture_item' => [
                'path' => '/api/item/{id}',
                'methods' => ['GET'],
                'controller' => 'fixture_controller::item',
                'env' => null,
                'requirements' => ['id' => '\d+'],
            ],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }
}
