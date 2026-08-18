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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Command;

use DateTimeImmutable;
use DateTimeZone;
use KonradMichalik\Typo3Routing\Command\RouteDebugCommand;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * RouteDebugCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteDebugCommand::class)]
final class RouteDebugCommandTest extends TestCase
{
    #[Test]
    public function rendersRoutesAsTable(): void
    {
        $tester = $this->tester($this->registry());

        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('example_count', $display);
        self::assertStringContainsString('/api/example/dev', $display);
        self::assertStringContainsString('GET, POST', $display);
        self::assertStringContainsString('Development', $display);
        self::assertStringContainsString('id: \d+', $display);
    }

    #[Test]
    public function rendersRoutesAsJson(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--json' => true]);

        /** @var list<array{name: string, path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('example_count', $data[0]['name']);
        self::assertSame('/api/example/count', $data[0]['path']);
        self::assertSame(['GET'], $data[0]['methods']);
        self::assertSame([], $data[0]['requirements']);
        self::assertSame('Development', $data[1]['env']);
        self::assertSame(['id' => '\d+'], $data[1]['requirements']);
    }

    #[Test]
    public function detailShowsTheDeprecationStateWithSunsetAndSuccessor(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['example_v1' => ['path' => '/api/example/v1', 'methods' => ['GET'], 'controller' => 'ctrl::v1', 'env' => null, 'requirements' => []]];
        $registry = new RouteRegistry($routes, new ServiceLocator([]), deprecations: [
            'example_v1' => [
                'since' => (new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')))->getTimestamp(),
                'sunset' => (new DateTimeImmutable('2026-12-31', new DateTimeZone('UTC')))->getTimestamp(),
                'successor' => 'example_v2',
                'documentation' => null,
            ],
        ]);
        $tester = $this->tester($registry);

        $tester->execute(['name' => 'example_v1']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('2026-01-01', $display);
        self::assertStringContainsString('2026-12-31', $display);
        self::assertStringContainsString('example_v2', $display);
    }

    #[Test]
    public function detailFallsBackForARouteWithoutADeprecation(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['name' => 'example_count']);

        self::assertMatchesRegularExpression('/Deprecated\s+-/', $tester->getDisplay());
    }

    #[Test]
    public function includesTheDeprecationInJsonOutput(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['example_v1' => ['path' => '/api/example/v1', 'methods' => ['GET'], 'controller' => 'ctrl::v1', 'env' => null, 'requirements' => []]];
        $registry = new RouteRegistry($routes, new ServiceLocator([]), deprecations: [
            'example_v1' => ['since' => 1234, 'sunset' => null, 'successor' => null, 'documentation' => null],
        ]);
        $tester = $this->tester($registry);

        $tester->execute(['--json' => true]);

        /** @var list<array{name: string, deprecated: array{since: int}}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1234, $data[0]['deprecated']['since']);
    }

    #[Test]
    public function filtersToOnlyDeprecatedRoutes(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'example_v1' => ['path' => '/api/example/v1', 'methods' => ['GET'], 'controller' => 'ctrl::v1', 'env' => null, 'requirements' => []],
            'example_v2' => ['path' => '/api/example/v2', 'methods' => ['GET'], 'controller' => 'ctrl::v2', 'env' => null, 'requirements' => []],
        ];
        $registry = new RouteRegistry($routes, new ServiceLocator([]), deprecations: [
            'example_v1' => ['since' => 1, 'sunset' => null, 'successor' => null, 'documentation' => null],
        ]);
        $tester = $this->tester($registry);

        $tester->execute(['--deprecated' => true, '--json' => true]);

        /** @var list<array{name: string}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data);
        self::assertSame('example_v1', $data[0]['name']);
    }

    #[Test]
    public function warnsWhenNoRoutesAreRegistered(): void
    {
        $tester = $this->tester(new RouteRegistry([], new ServiceLocator([])));

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No attribute routes', $tester->getDisplay());
    }

    #[Test]
    public function rendersAuthAndCsrfColumns(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Acme\\TokenAuthenticator', $display);
        self::assertStringContainsString('routing/secure', $display);
    }

    #[Test]
    public function includesAuthAndCsrfInJson(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--json' => true]);

        /** @var list<array{name: string, auth: list<string>, csrf: string|null}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);

        // Public route.
        self::assertSame([], $data[0]['auth']);
        self::assertNull($data[0]['csrf']);
        // Protected route.
        self::assertSame(['Acme\\TokenAuthenticator'], $data[2]['auth']);
        self::assertSame('routing/secure', $data[2]['csrf']);
    }

    #[Test]
    public function includesCacheRateLimitAndArgumentsInJson(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--json' => true]);

        /** @var list<array{name: string, cache: array{lifetime: int}|null, rateLimit: array{policy: string}|null, arguments: list<array{name: string, source: string}>}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);

        // Cached route.
        self::assertNotNull($data[0]['cache']);
        self::assertSame(3600, $data[0]['cache']['lifetime']);
        self::assertNull($data[0]['rateLimit']);
        // Rate-limited route with a resolved argument.
        self::assertNotNull($data[1]['rateLimit']);
        self::assertSame('sliding_window', $data[1]['rateLimit']['policy']);
        self::assertSame('id', $data[1]['arguments'][0]['name']);
        self::assertSame('path', $data[1]['arguments'][0]['source']);
    }

    #[Test]
    public function showsFullDetailForAnExactName(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['name' => 'example_dev']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('/api/example/dev', $display);
        self::assertStringContainsString('Development', $display);
        self::assertStringContainsString('sliding_window', $display);
        self::assertStringContainsString('$id', $display);
        self::assertStringContainsString('from path', $display);
    }

    #[Test]
    public function detailRendersCacheAndAnyMethodsAndEmptyFallbacks(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['name' => 'example_count']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('lifetime: 3600', $display);
        self::assertStringContainsString('pages', $display);

        $tester->execute(['name' => 'example_any']);
        $any = $tester->getDisplay();

        self::assertStringContainsString('ANY', $any);
    }

    #[Test]
    public function detailRendersSchemesAndHostWithFallbacks(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['name' => 'example_secure']);
        $secure = $tester->getDisplay();
        self::assertStringContainsString('Schemes', $secure);
        self::assertStringContainsString('https', $secure);
        self::assertStringContainsString('Host', $secure);
        self::assertStringContainsString('api.example.com', $secure);

        $tester->execute(['name' => 'example_count']);
        $count = $tester->getDisplay();
        self::assertStringContainsString('ANY', $count);
    }

    #[Test]
    public function detailRendersTheCaseInsensitiveOptIn(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['name' => 'example_loose']);
        self::assertMatchesRegularExpression('/Case insensitive\s+yes/', $tester->getDisplay());

        $tester->execute(['name' => 'example_count']);
        self::assertMatchesRegularExpression('/Case insensitive\s+no/', $tester->getDisplay());
    }

    #[Test]
    public function jsonOutputCarriesTheCaseInsensitiveOptIn(): void
    {
        $tester = $this->tester($this->registry());
        $tester->execute(['--json' => true]);

        /** @var list<array{name: string, caseInsensitive: bool}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        $flags = array_column($data, 'caseInsensitive', 'name');

        self::assertTrue($flags['example_loose']);
        self::assertFalse($flags['example_count']);
    }

    #[Test]
    public function detailRendersTagsWithAFallback(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['name' => 'example_tagged']);
        self::assertMatchesRegularExpression('/Tags\s+Tagged/', $tester->getDisplay());

        $tester->execute(['name' => 'example_count']);
        self::assertMatchesRegularExpression('/Tags\s+-/', $tester->getDisplay());
    }

    #[Test]
    public function jsonOutputCarriesTags(): void
    {
        $tester = $this->tester($this->registry());
        $tester->execute(['--json' => true]);

        /** @var list<array{name: string, tags: list<string>}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        $tags = array_column($data, 'tags', 'name');

        self::assertSame(['Tagged'], $tags['example_tagged']);
        self::assertSame([], $tags['example_count']);
    }

    #[Test]
    public function detailRendersTheCanonicalOptIn(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['name' => 'example_canonical']);
        self::assertMatchesRegularExpression('/Canonical redirect\s+yes/', $tester->getDisplay());

        $tester->execute(['name' => 'example_count']);
        self::assertMatchesRegularExpression('/Canonical redirect\s+no/', $tester->getDisplay());
    }

    #[Test]
    public function jsonOutputCarriesTheCanonicalOptIn(): void
    {
        $tester = $this->tester($this->registry());
        $tester->execute(['--json' => true]);

        /** @var list<array{name: string, canonical: bool}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        $flags = array_column($data, 'canonical', 'name');

        self::assertTrue($flags['example_canonical']);
        self::assertFalse($flags['example_count']);
    }

    #[Test]
    public function detailRendersSitesAndLanguagesWithFallbacks(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['name' => 'example_scoped']);
        $scoped = $tester->getDisplay();
        self::assertStringContainsString('Sites', $scoped);
        self::assertStringContainsString('main', $scoped);
        self::assertStringContainsString('Languages', $scoped);

        $tester->execute(['name' => 'example_count']);
        $unscoped = $tester->getDisplay();
        self::assertMatchesRegularExpression('/Sites\s+ANY/', $unscoped);
        self::assertMatchesRegularExpression('/Languages\s+ANY/', $unscoped);
    }

    #[Test]
    public function jsonOutputCarriesSitesAndLanguages(): void
    {
        $tester = $this->tester($this->registry());
        $tester->execute(['--json' => true]);

        /** @var list<array{name: string, sites: list<string>, languages: list<int>}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        $sites = array_column($data, 'sites', 'name');
        $languages = array_column($data, 'languages', 'name');

        self::assertSame(['main'], $sites['example_scoped']);
        self::assertSame([0], $languages['example_scoped']);
        self::assertSame([], $sites['example_count']);
        self::assertSame([], $languages['example_count']);
    }

    #[Test]
    public function truncatesLongDescriptionsInTableOutputButNotInJsonOrDetail(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute([]);
        $table = $tester->getDisplay();
        self::assertStringNotContainsString('Charges a payment for the current basket, only reachable over HTTPS.', $table);
        self::assertStringContainsString('…', $table);

        $tester->execute(['--json' => true]);
        /** @var list<array{name: string, description: string|null}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Charges a payment for the current basket, only reachable over HTTPS.', $data[2]['description']);
        self::assertNull($data[0]['description']);

        $tester->execute(['name' => 'example_secure']);
        self::assertStringContainsString('Charges a payment for the current basket, only reachable over HTTPS.', $tester->getDisplay());
    }

    #[Test]
    public function filtersByNameSubstring(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['name' => 'count']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('example_count', $display);
        self::assertStringNotContainsString('example_dev', $display);
    }

    #[Test]
    public function filtersByMethodCaseInsensitivelyIncludingAnyMethodRoutes(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--method' => 'post']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('example_dev', $display);
        self::assertStringContainsString('example_secure', $display);
        self::assertStringContainsString('example_any', $display); // empty methods accept any
        self::assertStringNotContainsString('example_count', $display);
    }

    #[Test]
    public function filtersByPathSubstring(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--path' => '/dev']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('example_dev', $display);
        self::assertStringNotContainsString('example_count', $display);
    }

    #[Test]
    public function filtersByEnv(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--env' => 'Development']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('example_dev', $display);
        self::assertStringNotContainsString('example_count', $display);
    }

    #[Test]
    public function filtersProtectedRoutes(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--protected' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('example_secure', $display);
        self::assertStringNotContainsString('example_count', $display);
    }

    #[Test]
    public function filtersCachedRoutes(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--cached' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('example_count', $display);
        self::assertStringNotContainsString('example_secure', $display);
    }

    #[Test]
    public function filtersRateLimitedRoutes(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--rate-limited' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('example_dev', $display);
        self::assertStringNotContainsString('example_count', $display);
    }

    #[Test]
    public function filtersCsrfRoutes(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--csrf' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('example_secure', $display);
        self::assertStringNotContainsString('example_count', $display);
    }

    #[Test]
    public function combinesFiltersWithAndSemantics(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--method' => 'POST', '--protected' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('example_secure', $display); // POST + auth
        self::assertStringNotContainsString('example_dev', $display);  // POST but unprotected
    }

    #[Test]
    public function listsOnlyUnprotectedRoutesWithTheFilter(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--unprotected' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('unprotected', $display); // active-filter comment
        self::assertStringContainsString('example_count', $display);
        self::assertStringNotContainsString('example_secure', $display);
    }

    #[Test]
    public function warnsWhenNoRouteMatchesTheFilter(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--env' => 'Nonexistent']);

        self::assertStringContainsString('No matching attribute routes', $tester->getDisplay());
    }

    #[Test]
    public function honoursFiltersInJsonOutput(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--cached' => true, '--json' => true]);

        /** @var list<array{name: string}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data);
        self::assertSame('example_count', $data[0]['name']);
    }

    private function tester(RouteRegistry $registry): CommandTester
    {
        return new CommandTester(new RouteDebugCommand($registry));
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, canonical?: bool, sites?: list<string>, languages?: list<int>}> $routes */
        $routes = [
            'example_count' => ['path' => '/api/example/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'example_dev' => ['path' => '/api/example/dev', 'methods' => ['GET', 'POST'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => ['id' => '\d+']],
            'example_secure' => ['path' => '/api/example/secure', 'methods' => ['POST'], 'controller' => 'ctrl::secure', 'env' => null, 'requirements' => [], 'schemes' => ['https'], 'host' => 'api.example.com', 'description' => 'Charges a payment for the current basket, only reachable over HTTPS.'],
            'example_any' => ['path' => '/api/example/any', 'methods' => [], 'controller' => 'ctrl::any', 'env' => null, 'requirements' => []],
            'example_loose' => ['path' => '/api/example/loose', 'methods' => ['GET'], 'controller' => 'ctrl::loose', 'env' => null, 'requirements' => [], 'caseInsensitive' => true],
            'example_tagged' => ['path' => '/api/example/tagged', 'methods' => ['GET'], 'controller' => 'ctrl::tagged', 'env' => null, 'requirements' => [], 'tags' => ['Tagged']],
            'example_canonical' => ['path' => '/api/example/canonical', 'methods' => ['GET'], 'controller' => 'ctrl::canonical', 'env' => null, 'requirements' => [], 'canonical' => true],
            'example_scoped' => ['path' => '/api/example/scoped', 'methods' => ['GET'], 'controller' => 'ctrl::scoped', 'env' => null, 'requirements' => [], 'sites' => ['main'], 'languages' => [0]],
        ];

        /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> $cacheConfigs */
        $cacheConfigs = ['example_count' => ['lifetime' => 3600, 'tags' => ['pages'], 'ignoreParams' => []]];
        /** @var array<string, array{limit: int, interval: string, policy: string, keyBy: string}> $rateLimits */
        $rateLimits = ['example_dev' => ['limit' => 60, 'interval' => '1 minute', 'policy' => 'sliding_window', 'keyBy' => 'ip']];
        /** @var array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>> $arguments */
        $arguments = ['example_dev' => [['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]]];
        /** @var array<string, list<array{service: string, options: array<string, mixed>}>> $authenticators */
        $authenticators = ['example_secure' => [['service' => 'Acme\\TokenAuthenticator', 'options' => []]]];
        /** @var array<string, string> $requestTokenScopes */
        $requestTokenScopes = ['example_secure' => 'routing/secure'];

        return new RouteRegistry($routes, new ServiceLocator([]), $cacheConfigs, $rateLimits, $arguments, $authenticators, $requestTokenScopes);
    }
}
