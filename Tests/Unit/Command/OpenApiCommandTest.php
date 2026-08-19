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
use KonradMichalik\Typo3Routing\OpenApi\{JsonSchemaMapper, OpenApiGenerator, ResponsesBuilder};
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * OpenApiCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(OpenApiCommand::class)]
final class OpenApiCommandTest extends TestCase
{
    #[Test]
    public function outputsOpenApiDocumentWithDefaults(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([]);
        $document = $this->decode($tester->getDisplay());

        self::assertSame(0, $exitCode);
        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame(['title' => 'TYPO3 Routing API', 'version' => '1.0.0'], $document['info']);
        self::assertArrayHasKey('/api/x', $document['paths']);
    }

    /**
     * Route paths are stored in full, so any base URL would be doubled into every path. Omitting
     * `servers` means "/" per the OpenAPI spec — exactly right for full paths.
     */
    #[Test]
    public function omitsServersWhenNoServerOptionIsGiven(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        self::assertArrayNotHasKey('servers', $this->decode($tester->getDisplay()));
    }

    #[Test]
    public function honorsTitleVersionAndServerOptions(): void
    {
        $tester = $this->tester();

        $tester->execute(['--title' => 'My API', '--api-version' => '2.5.0', '--server' => 'https://api.example.com']);
        $document = $this->decode($tester->getDisplay());

        self::assertSame(['title' => 'My API', 'version' => '2.5.0'], $document['info']);
        self::assertSame([['url' => 'https://api.example.com']], $document['servers']);
    }

    #[Test]
    public function prettyPrintsWhenRequested(): void
    {
        $tester = $this->tester();

        $tester->execute(['--pretty' => true]);

        self::assertStringContainsString("\n", trim($tester->getDisplay()));
    }

    private function tester(): CommandTester
    {
        $schemas = new JsonSchemaMapper();

        return new CommandTester(new OpenApiCommand(new OpenApiGenerator($this->registry(), $schemas, new ResponsesBuilder($schemas))));
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
