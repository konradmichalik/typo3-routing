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

use KonradMichalik\Typo3Routing\Command\OpenApiCommand;
use KonradMichalik\Typo3Routing\OpenApi\OpenApiGenerator;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * OpenApiCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(OpenApiCommand::class)]
final class OpenApiCommandTest extends TestCase
{
    #[Test]
    public function outputsOpenApiDocumentWithDefaultsAndConfiguredServer(): void
    {
        $tester = $this->tester('/api/');

        $exitCode = $tester->execute([]);
        $document = $this->decode($tester->getDisplay());

        self::assertSame(0, $exitCode);
        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame(['title' => 'TYPO3 Routing API', 'version' => '1.0.0'], $document['info']);
        self::assertSame([['url' => '/api/']], $document['servers']);
        self::assertArrayHasKey('/api/x', $document['paths']);
    }

    #[Test]
    public function honorsTitleVersionAndServerOptions(): void
    {
        $tester = $this->tester('/api/');

        $tester->execute(['--title' => 'My API', '--api-version' => '2.5.0', '--server' => 'https://api.example.com']);
        $document = $this->decode($tester->getDisplay());

        self::assertSame(['title' => 'My API', 'version' => '2.5.0'], $document['info']);
        self::assertSame([['url' => 'https://api.example.com']], $document['servers']);
    }

    #[Test]
    public function prettyPrintsWhenRequested(): void
    {
        $tester = $this->tester('/api/');

        $tester->execute(['--pretty' => true]);

        self::assertStringContainsString("\n", trim($tester->getDisplay()));
    }

    #[Test]
    public function fallsBackToDefaultServerWhenPrefixIsNotAString(): void
    {
        $tester = $this->tester(null);

        $tester->execute([]);
        $document = $this->decode($tester->getDisplay());

        self::assertSame([['url' => '/api/']], $document['servers']);
    }

    #[Test]
    public function fallsBackToDefaultServerWhenExtensionConfigurationThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

        $tester = new CommandTester(new OpenApiCommand(new OpenApiGenerator($this->registry()), $extensionConfiguration));
        $tester->execute([]);

        self::assertSame([['url' => '/api/']], $this->decode($tester->getDisplay())['servers']);
    }

    private function tester(?string $prefix): CommandTester
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($prefix);

        return new CommandTester(new OpenApiCommand(new OpenApiGenerator($this->registry()), $extensionConfiguration));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $output): array
    {
        /** @var array<string, mixed> $document */
        $document = json_decode(trim($output), true, 512, \JSON_THROW_ON_ERROR);

        return $document;
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'x' => ['path' => '/api/x', 'methods' => ['GET'], 'controller' => 'ctrl::x', 'env' => null, 'requirements' => []],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }
}
