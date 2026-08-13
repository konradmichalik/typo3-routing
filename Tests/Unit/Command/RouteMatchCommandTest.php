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

use KonradMichalik\Typo3Routing\Command\RouteMatchCommand;
use KonradMichalik\Typo3Routing\Routing\{RouteMatcher, RouteRegistry};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * RouteMatchCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteMatchCommand::class)]
final class RouteMatchCommandTest extends TestCase
{
    #[Test]
    public function reportsTheWinningRouteForAStaticPath(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['path' => '/api/example/count']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Matched route "example_count"', $display);
        self::assertStringContainsString('ctrl::count', $display);
    }

    /**
     * The simulation has to answer for the dispatcher, which serves both forms.
     */
    #[Test]
    public function reportsTheWinningRouteForAPathWithAnAddedTrailingSlash(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['path' => '/api/example/count/']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Matched route "example_count"', $tester->getDisplay());
    }

    #[Test]
    public function resolvesAndReportsPathPlaceholders(): void
    {
        $tester = $this->tester();

        $tester->execute(['path' => '/api/example/item/42']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Matched route "example_item"', $display);
        self::assertStringContainsString('id: 42', $display);
    }

    #[Test]
    public function reportsAMatchWithoutParametersWithADash(): void
    {
        $tester = $this->tester();

        $tester->execute(['path' => '/api/example/count']);

        self::assertStringContainsString('Parameters', $tester->getDisplay());
        self::assertStringContainsString('-', $tester->getDisplay());
    }

    #[Test]
    public function acceptsAPathWithoutALeadingSlash(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['path' => 'api/example/count']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Matched route "example_count"', $tester->getDisplay());
    }

    #[Test]
    public function reportsWhenNoRouteMatches(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['path' => '/api/example/nope']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No route matches', $tester->getDisplay());
    }

    #[Test]
    public function reportsMethodNotAllowedWithTheAllowedMethods(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['path' => '/api/example/dev', '--method' => 'delete']);
        $display = $tester->getDisplay();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not allowed', $display);
        self::assertStringContainsString('GET', $display);
        self::assertStringContainsString('POST', $display);
    }

    #[Test]
    public function notesAnEnvBoundRoute(): void
    {
        $tester = $this->tester();

        $tester->execute(['path' => '/api/example/dev']);

        self::assertStringContainsString('Development', $tester->getDisplay());
        self::assertStringContainsString('only reachable', $tester->getDisplay());
    }

    #[Test]
    public function honoursSchemeAndHostConstraints(): void
    {
        // example_secure is bound to https + api.example.com; the default localhost host misses it.
        $missed = $this->tester();
        $exitCode = $missed->execute(['path' => '/api/example/secure', '--method' => 'POST']);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No route matches', $missed->getDisplay());

        $matched = $this->tester();
        $matched->execute(['path' => '/api/example/secure', '--method' => 'POST', '--scheme' => 'https', '--host' => 'api.example.com']);
        self::assertStringContainsString('Matched route "example_secure"', $matched->getDisplay());
    }

    private function tester(): CommandTester
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('1');

        return new CommandTester(new RouteMatchCommand(new RouteMatcher($this->registry(), $extensionConfiguration)));
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes?: list<string>, host?: string|null}> $routes */
        $routes = [
            'example_count' => ['path' => '/api/example/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'example_dev' => ['path' => '/api/example/dev', 'methods' => ['GET', 'POST'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => []],
            'example_secure' => ['path' => '/api/example/secure', 'methods' => ['POST'], 'controller' => 'ctrl::secure', 'env' => null, 'requirements' => [], 'schemes' => ['https'], 'host' => 'api.example.com'],
            'example_item' => ['path' => '/api/example/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }
}
