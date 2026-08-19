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

use KonradMichalik\Typo3Routing\Routing\{RequirementMismatchException, RouteMatcher, RouteRegistry};
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
        self::assertFalse($match['_canonicalVariant']);
    }

    #[Test]
    public function matchesADeclaredPathWithAnAddedTrailingSlash(): void
    {
        $match = $this->matcher()->match('/api/count/', $this->context());

        self::assertSame('count', $match['_route']);
        self::assertTrue($match['_canonicalVariant']);
    }

    #[Test]
    public function matchesAPathDeclaredWithATrailingSlashWithoutOne(): void
    {
        $match = $this->matcher()->match('/api/slashed', $this->context());

        self::assertSame('slashed', $match['_route']);
        self::assertTrue($match['_canonicalVariant']);
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

    #[Test]
    public function matchesAnOptedInRouteRegardlessOfCase(): void
    {
        $match = $this->matcher()->match('/API/Loose', $this->context());

        self::assertSame('loose', $match['_route']);
        // A case-insensitive match is always a tolerated variant, even without a trailing-slash retry.
        self::assertTrue($match['_canonicalVariant']);
    }

    #[Test]
    public function theCaseInsensitiveFallbackStillToleratesATrailingSlash(): void
    {
        $match = $this->matcher()->match('/API/Loose/', $this->context());

        self::assertSame('loose', $match['_route']);
    }

    #[Test]
    public function aRouteThatDidNotOptInStaysCaseSensitive(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->matcher()->match('/API/Count', $this->context());
    }

    #[Test]
    public function placeholderValuesKeepTheirCaseAfterACaseInsensitiveMatch(): void
    {
        $match = $this->matcher()->match('/API/Loose/abc', $this->context());

        self::assertSame('looseItem', $match['_route']);
        self::assertSame('abc', $match['code']);
    }

    /**
     * The "i" modifier applies to the whole compiled regex, so the fallback would otherwise let a value
     * through that the declared requirement rejects.
     */
    #[Test]
    public function aPlaceholderRequirementIsStillEnforcedAfterACaseInsensitiveMatch(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->matcher()->match('/API/Loose/ABC', $this->context());
    }

    /**
     * A bare miss and a miss that got as far as a requirement are indistinguishable to anyone catching
     * the plain exception, and the second one is the harder to diagnose. So the rejection names what it
     * rejected.
     */
    #[Test]
    public function theEnforcedRequirementNamesTheRouteAndTheRejectedValue(): void
    {
        try {
            $this->matcher()->match('/API/Loose/ABC', $this->context());
            self::fail('Expected the requirement to reject the value.');
        } catch (RequirementMismatchException $exception) {
            self::assertSame('looseItem', $exception->routeName);
            self::assertSame('code', $exception->parameter);
            self::assertSame('ABC', $exception->value);
            self::assertSame('[a-z]+', $exception->requirement);
        }
    }

    /**
     * Without a single opted-in route there is no fallback matcher at all, and the original miss has to
     * surface unchanged — this is the default installation.
     */
    #[Test]
    public function aRegistryWithoutAnyOptedInRouteReportsTheOriginalMiss(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('1');

        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []]];
        $matcher = new RouteMatcher(new RouteRegistry($routes, new ServiceLocator([])), $extensionConfiguration);

        $this->expectException(ResourceNotFoundException::class);

        $matcher->match('/API/Count', $this->context());
    }

    #[Test]
    public function aWrongMethodOnACaseInsensitiveMatchIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);

        $this->matcher()->match('/API/Loose', $this->context('DELETE'));
    }

    /**
     * The revalidation regex in assertRequirementsHold() has to carry the same "u" modifier the route
     * itself compiled with, or a Unicode-only requirement like \p{L}+ rejects every valid UTF-8 value
     * after a case-insensitive fallback match.
     */
    #[Test]
    public function aUnicodeRequirementIsSatisfiedAfterACaseInsensitiveMatch(): void
    {
        $match = $this->matcher()->match('/API/Loose-Unicode/café', $this->context());

        self::assertSame('looseUnicode', $match['_route']);
        self::assertSame('café', $match['name']);
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
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, caseInsensitive?: bool, canonical?: bool}> $routes */
        $routes = [
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'slashed' => ['path' => '/api/slashed/', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'submit' => ['path' => '/api/submit', 'methods' => ['POST'], 'controller' => 'ctrl::submit', 'env' => null, 'requirements' => []],
            'item' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']],
            'loose' => ['path' => '/api/loose', 'methods' => ['GET'], 'controller' => 'ctrl::loose', 'env' => null, 'requirements' => [], 'caseInsensitive' => true],
            'looseItem' => ['path' => '/api/loose/{code}', 'methods' => ['GET'], 'controller' => 'ctrl::looseItem', 'env' => null, 'requirements' => ['code' => '[a-z]+'], 'caseInsensitive' => true],
            'looseUnicode' => ['path' => '/api/loose-unicode/{name}', 'methods' => ['GET'], 'controller' => 'ctrl::looseUnicode', 'env' => null, 'requirements' => ['name' => '\p{L}+'], 'caseInsensitive' => true],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }
}
