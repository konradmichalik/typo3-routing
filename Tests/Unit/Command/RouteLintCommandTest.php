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

use KonradMichalik\Typo3Routing\Command\RouteLintCommand;
use KonradMichalik\Typo3Routing\Routing\{RouteLinter, RouteRegistry};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * RouteLintCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteLintCommand::class)]
final class RouteLintCommandTest extends TestCase
{
    #[Test]
    public function reportsSuccessAndExitsZeroForACleanRouteSet(): void
    {
        $tester = $this->tester($this->registry([
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
        ]));

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No findings', $tester->getDisplay());
    }

    #[Test]
    public function rendersFindingsAsATableAndExitsZeroWithoutStrict(): void
    {
        $tester = $this->tester($this->registry([
            'open' => ['path' => '/{slug}', 'methods' => ['GET'], 'controller' => 'ctrl::open', 'env' => null, 'requirements' => []],
        ]));

        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('no-static-prefix', $display);
        self::assertStringContainsString('open', $display);
        self::assertStringContainsString('ctrl::open', $display);
        self::assertStringContainsString('1 finding', $display);
    }

    #[Test]
    public function exitsNonZeroWithStrictWhenAnyFindingExists(): void
    {
        $tester = $this->tester($this->registry([
            'open' => ['path' => '/{slug}', 'methods' => ['GET'], 'controller' => 'ctrl::open', 'env' => null, 'requirements' => []],
        ]));

        $exitCode = $tester->execute(['--strict' => true]);

        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function strictHasNoEffectOnACleanRouteSet(): void
    {
        $tester = $this->tester($this->registry([
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
        ]));

        $exitCode = $tester->execute(['--strict' => true]);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function jsonOutputCarriesEachFindingsRouteAndController(): void
    {
        $tester = $this->tester($this->registry([
            'open' => ['path' => '/{slug}', 'methods' => ['GET'], 'controller' => 'ctrl::open', 'env' => null, 'requirements' => []],
        ]));

        $tester->execute(['--json' => true]);

        /** @var list<array{severity: string, check: string, route: string|null, controller: string|null, message: string}> $data */
        $data = json_decode(trim($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data);
        self::assertSame('no-static-prefix', $data[0]['check']);
        self::assertSame('open', $data[0]['route']);
        self::assertSame('ctrl::open', $data[0]['controller']);
    }

    #[Test]
    public function jsonOutputExitsZeroWithoutStrictAndNonZeroWithStrict(): void
    {
        $registry = $this->registry([
            'open' => ['path' => '/{slug}', 'methods' => ['GET'], 'controller' => 'ctrl::open', 'env' => null, 'requirements' => []],
        ]);

        $lenient = $this->tester($registry)->execute(['--json' => true]);
        $strict = $this->tester($registry)->execute(['--json' => true, '--strict' => true]);

        self::assertSame(0, $lenient);
        self::assertSame(1, $strict);
    }

    #[Test]
    public function fallsBackToNoExclusivePrefixesWhenExtensionConfigurationThrows(): void
    {
        $registry = $this->registry([
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
        ]);

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

        $tester = new CommandTester(new RouteLintCommand($registry, new RouteLinter(), $extensionConfiguration));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No findings', $tester->getDisplay());
    }

    #[Test]
    public function readsTheConfiguredExclusivePrefixesFromExtensionConfiguration(): void
    {
        $registry = $this->registry([
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
        ]);

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('/mcp/');

        $tester = new CommandTester(new RouteLintCommand($registry, new RouteLinter(), $extensionConfiguration));
        $tester->execute([]);

        self::assertStringContainsString('unused-exclusive-prefix', $tester->getDisplay());
        self::assertStringContainsString('/mcp/', $tester->getDisplay());
    }

    private function tester(RouteRegistry $registry): CommandTester
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('');

        return new CommandTester(new RouteLintCommand($registry, new RouteLinter(), $extensionConfiguration));
    }

    /**
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes
     */
    private function registry(array $routes): RouteRegistry
    {
        return new RouteRegistry($routes, new ServiceLocator([]));
    }
}
