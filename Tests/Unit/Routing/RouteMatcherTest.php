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

use KonradMichalik\Typo3Routing\Routing\{RouteMatcher, RouteRegistry};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * RouteMatcherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteMatcher::class)]
final class RouteMatcherTest extends TestCase
{
    #[Test]
    public function matchesAPathExactlyAsDeclared(): void
    {
        $match = $this->matcher()->match('/api/count', $this->context());

        self::assertSame('count', $match['_route']);
    }

    #[Test]
    public function matchesADeclaredPathWithAnAddedTrailingSlash(): void
    {
        $match = $this->matcher()->match('/api/count/', $this->context());

        self::assertSame('count', $match['_route']);
    }

    #[Test]
    public function matchesAPathDeclaredWithATrailingSlashWithoutOne(): void
    {
        $match = $this->matcher()->match('/api/slashed', $this->context());

        self::assertSame('slashed', $match['_route']);
    }

    #[Test]
    public function toleranceSurvivesPlaceholders(): void
    {
        $match = $this->matcher()->match('/api/item/42/', $this->context());

        self::assertSame('item', $match['_route']);
        self::assertSame('42', $match['id']);
    }

    #[Test]
    public function aPathMatchingNeitherVariantIsNotFound(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->matcher()->match('/api/nope', $this->context());
    }

    #[Test]
    public function theRootPathIsNeverStrippedToAnEmptyPath(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->matcher()->match('/', $this->context());
    }

    #[Test]
    public function aWrongMethodOnTheExactPathStaysMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);

        $this->matcher()->match('/api/submit', $this->context('GET'));
    }

    #[Test]
    public function aWrongMethodOnTheTrailingSlashVariantIsMethodNotAllowedToo(): void
    {
        try {
            $this->matcher()->match('/api/submit/', $this->context('GET'));
        } catch (MethodNotAllowedException $exception) {
            self::assertSame(['POST'], $exception->getAllowedMethods());

            return;
        }

        self::fail('Expected a MethodNotAllowedException carrying the allowed methods.');
    }

    #[Test]
    public function theToleranceCanBeSwitchedOff(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->matcher('0')->match('/api/count/', $this->context());
    }

    #[Test]
    public function anUnconfiguredExtensionKeepsTheToleranceOfTheDefaultConfiguration(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured', 1750000030));

        $matcher = new RouteMatcher($this->registry(), $extensionConfiguration);

        self::assertSame('count', $matcher->match('/api/count/', $this->context())['_route']);
    }

    private function matcher(string $trailingSlash = '1'): RouteMatcher
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($trailingSlash);

        return new RouteMatcher($this->registry(), $extensionConfiguration);
    }

    private function context(string $method = 'GET'): RequestContext
    {
        $context = new RequestContext();
        $context->setMethod($method);

        return $context;
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'slashed' => ['path' => '/api/slashed/', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'submit' => ['path' => '/api/submit', 'methods' => ['POST'], 'controller' => 'ctrl::submit', 'env' => null, 'requirements' => []],
            'item' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }
}
