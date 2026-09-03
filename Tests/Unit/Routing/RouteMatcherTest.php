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

    #[Test]
    public function aLegacyPathMatchesAndRewritesItsRouteNameToTheOwner(): void
    {
        $match = $this->matcher()->match('/api/legacy-count', $this->context());

        self::assertSame('count', $match['_route']);
        self::assertSame('count', $match['_legacyOf']);
        self::assertTrue($match['_canonicalVariant']);
    }

    #[Test]
    public function aLegacyPathToleratesATrailingSlashToo(): void
    {
        $match = $this->matcher()->match('/api/legacy-count/', $this->context());

        self::assertSame('count', $match['_route']);
    }

    #[Test]
    public function aLegacyPathSurvivesPlaceholders(): void
    {
        $match = $this->matcher()->match('/api/legacy-item/42', $this->context());

        self::assertSame('item', $match['_route']);
        self::assertSame('42', $match['id']);
    }

    #[Test]
    public function aWrongMethodOnALegacyPathIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);

        $this->matcher()->match('/api/legacy-submit', $this->context('GET'));
    }

    /**
     * Without a single legacy path there is no fallback matcher at all, and the original miss has to
     * surface unchanged — this is the default installation.
     */
    #[Test]
    public function aRegistryWithoutAnyLegacyPathReportsTheOriginalMiss(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('1');

        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []]];
        $matcher = new RouteMatcher(new RouteRegistry($routes, new ServiceLocator([])), $extensionConfiguration);

        $this->expectException(ResourceNotFoundException::class);

        $matcher->match('/api/legacy-count', $this->context());
    }

    /**
     * The declared scheme is not a matching constraint the way a wrong path is: everything else about
     * the request is right, so the match has to survive and carry the scheme the client should have
     * used — a 404 would hide a route that plainly exists.
     */
    #[Test]
    public function aSchemeConstrainedRouteStillMatchesOverTheWrongSchemeAndNamesTheDeclaredScheme(): void
    {
        $match = $this->matcher()->match('/api/secure', $this->context());

        self::assertSame('secure', $match['_route']);
        self::assertSame('https', $match['_schemeRedirect']);
        self::assertFalse($match['_canonicalVariant']);
    }

    #[Test]
    public function aSchemeConstrainedRouteOverItsOwnSchemeIsNeverFlaggedForRedirect(): void
    {
        $match = $this->matcher()->match('/api/secure', $this->context(scheme: 'https'));

        self::assertSame('secure', $match['_route']);
        self::assertArrayNotHasKey('_schemeRedirect', $match);
    }

    #[Test]
    public function theSchemeFallbackStillToleratesATrailingSlash(): void
    {
        $match = $this->matcher()->match('/api/secure/', $this->context());

        self::assertSame('secure', $match['_route']);
        self::assertTrue($match['_canonicalVariant']);
    }

    /**
     * A scheme declared in any casing is the same scheme; lowercasing it here is what keeps the
     * dispatcher's redirect target from pointing back at a URL that would be redirected again.
     */
    #[Test]
    public function theNamedSchemeIsNormalisedToLowerCase(): void
    {
        $match = $this->matcher()->match('/api/secure-upper', $this->context());

        self::assertSame('https', $match['_schemeRedirect']);
    }

    #[Test]
    public function aPlaceholderRequirementIsStillEnforcedOnASchemeMismatch(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->matcher()->match('/api/secure-item/abc', $this->context());
    }

    #[Test]
    public function aWrongMethodOnASchemeMismatchIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);

        $this->matcher()->match('/api/secure-submit', $this->context('GET'));
    }

    #[Test]
    public function aLegacyPathOfASchemeConstrainedRouteIsFlaggedForRedirectToo(): void
    {
        $match = $this->matcher()->match('/api/secure-old', $this->context());

        self::assertSame('secure', $match['_route']);
        self::assertSame('secure', $match['_legacyOf']);
        self::assertSame('https', $match['_schemeRedirect']);
    }

    /**
     * Without a single scheme-constrained route there is no fallback matcher at all, and the original
     * miss has to surface unchanged — this is the default installation.
     */
    #[Test]
    public function aRegistryWithoutASchemeConstrainedRouteReportsTheOriginalMiss(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('1');

        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []]];
        $matcher = new RouteMatcher(new RouteRegistry($routes, new ServiceLocator([])), $extensionConfiguration);

        $this->expectException(ResourceNotFoundException::class);

        $matcher->match('/api/secure', $this->context());
    }

    private function matcher(string $trailingSlash = '1'): RouteMatcher
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($trailingSlash);

        return new RouteMatcher($this->registry(), $extensionConfiguration);
    }

    private function context(string $method = 'GET', string $scheme = 'http'): RequestContext
    {
        $context = new RequestContext();
        $context->setMethod($method);
        $context->setScheme($scheme);

        return $context;
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, caseInsensitive?: bool, canonical?: bool, legacyPaths?: list<string>, schemes?: list<string>}> $routes */
        $routes = [
            'secure' => ['path' => '/api/secure', 'methods' => ['GET'], 'controller' => 'ctrl::secure', 'env' => null, 'requirements' => [], 'schemes' => ['https'], 'legacyPaths' => ['/api/secure-old']],
            'secureUpper' => ['path' => '/api/secure-upper', 'methods' => ['GET'], 'controller' => 'ctrl::secure', 'env' => null, 'requirements' => [], 'schemes' => ['HTTPS']],
            'secureItem' => ['path' => '/api/secure-item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::secure', 'env' => null, 'requirements' => ['id' => '\d+'], 'schemes' => ['https']],
            'secureSubmit' => ['path' => '/api/secure-submit', 'methods' => ['POST'], 'controller' => 'ctrl::secure', 'env' => null, 'requirements' => [], 'schemes' => ['https']],
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/legacy-count']],
            'slashed' => ['path' => '/api/slashed/', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'submit' => ['path' => '/api/submit', 'methods' => ['POST'], 'controller' => 'ctrl::submit', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/legacy-submit']],
            'item' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+'], 'legacyPaths' => ['/api/legacy-item/{id}']],
            'loose' => ['path' => '/api/loose', 'methods' => ['GET'], 'controller' => 'ctrl::loose', 'env' => null, 'requirements' => [], 'caseInsensitive' => true],
            'looseItem' => ['path' => '/api/loose/{code}', 'methods' => ['GET'], 'controller' => 'ctrl::looseItem', 'env' => null, 'requirements' => ['code' => '[a-z]+'], 'caseInsensitive' => true],
            'looseUnicode' => ['path' => '/api/loose-unicode/{name}', 'methods' => ['GET'], 'controller' => 'ctrl::looseUnicode', 'env' => null, 'requirements' => ['name' => '\p{L}+'], 'caseInsensitive' => true],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }
}
