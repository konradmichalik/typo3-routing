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
    public function listsOnlyUnprotectedRoutesWithTheFilter(): void
    {
        $tester = $this->tester($this->registry());

        $tester->execute(['--unprotected' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Unprotected Attribute Routes', $display);
        self::assertStringContainsString('example_count', $display);
        self::assertStringNotContainsString('example_secure', $display);
    }

    #[Test]
    public function warnsWhenNoUnprotectedRoutesExist(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'example_secure' => ['path' => '/api/example/secure', 'methods' => ['POST'], 'controller' => 'ctrl::secure', 'env' => null, 'requirements' => []],
        ];
        $registry = new RouteRegistry($routes, new ServiceLocator([]), [], [], [], ['example_secure' => [['service' => 'Acme\\TokenAuthenticator', 'options' => []]]]);

        $tester = $this->tester($registry);
        $tester->execute(['--unprotected' => true]);

        self::assertStringContainsString('No unprotected attribute routes', $tester->getDisplay());
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
        ];

        /** @var array<string, list<array{service: string, options: array<string, mixed>}>> $authenticators */
        $authenticators = ['example_secure' => [['service' => 'Acme\\TokenAuthenticator', 'options' => []]]];
        /** @var array<string, string> $requestTokenScopes */
        $requestTokenScopes = ['example_secure' => 'routing/secure'];

        return new RouteRegistry($routes, new ServiceLocator([]), [], [], [], $authenticators, $requestTokenScopes);
    }
}
