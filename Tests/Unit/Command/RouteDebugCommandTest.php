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
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'example_count' => ['path' => '/api/example/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'example_dev' => ['path' => '/api/example/dev', 'methods' => ['GET', 'POST'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => ['id' => '\d+']],
            'example_secure' => ['path' => '/api/example/secure', 'methods' => ['POST'], 'controller' => 'ctrl::secure', 'env' => null, 'requirements' => []],
            'example_any' => ['path' => '/api/example/any', 'methods' => [], 'controller' => 'ctrl::any', 'env' => null, 'requirements' => []],
        ];

        /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> $cacheConfigs */
        $cacheConfigs = ['example_count' => ['lifetime' => 3600, 'tags' => ['pages'], 'ignoreParams' => []]];
        /** @var array<string, array{limit: int, interval: string, policy: string}> $rateLimits */
        $rateLimits = ['example_dev' => ['limit' => 60, 'interval' => '1 minute', 'policy' => 'sliding_window']];
        /** @var array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>> $arguments */
        $arguments = ['example_dev' => [['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]]];
        /** @var array<string, list<array{service: string, options: array<string, mixed>}>> $authenticators */
        $authenticators = ['example_secure' => [['service' => 'Acme\\TokenAuthenticator', 'options' => []]]];
        /** @var array<string, string> $requestTokenScopes */
        $requestTokenScopes = ['example_secure' => 'routing/secure'];

        return new RouteRegistry($routes, new ServiceLocator([]), $cacheConfigs, $rateLimits, $arguments, $authenticators, $requestTokenScopes);
    }
}
