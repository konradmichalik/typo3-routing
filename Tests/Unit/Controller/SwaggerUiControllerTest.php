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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Controller;

use KonradMichalik\Typo3Routing\Controller\SwaggerUiController;
use KonradMichalik\Typo3Routing\Http\{HttpProblemException, RouteUrlGenerator, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\OpenApi\OpenApiGenerator;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * SwaggerUiControllerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(SwaggerUiController::class)]
final class SwaggerUiControllerTest extends TestCase
{
    #[Test]
    public function openApiJsonThrowsA404ProblemWhenTheFlagIsDisabled(): void
    {
        $controller = $this->controller('0');

        $this->expectException(HttpProblemException::class);

        try {
            $controller->openApiJson($this->request());
        } catch (HttpProblemException $exception) {
            self::assertSame(404, $exception->status);

            throw $exception;
        }
    }

    #[Test]
    public function openApiJsonThrowsA404ProblemWhenTheExtensionIsNotConfigured(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));
        $controller = new SwaggerUiController(new OpenApiGenerator($this->registry()), $this->urlGenerator(), $extensionConfiguration);

        $this->expectException(HttpProblemException::class);

        $controller->openApiJson($this->request());
    }

    #[Test]
    public function openApiJsonReturnsTheGeneratedDocumentWhenEnabled(): void
    {
        $controller = $this->controller('1');

        $response = $controller->openApiJson($this->request());

        self::assertSame(200, $response->getStatusCode());
        $document = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame([['url' => '/api/']], $document['servers']);
        self::assertArrayHasKey('/api/example', $document['paths']);
    }

    #[Test]
    public function openApiJsonFallsBackToTheDefaultServerPrefixWhenThatConfigKeyThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static function (string $extension, string $key): string {
                if ('swaggerUi' === $key) {
                    return '1';
                }

                throw new RuntimeException('prefix not configured', 7857001195);
            },
        );
        $controller = new SwaggerUiController(new OpenApiGenerator($this->registry()), $this->urlGenerator(), $extensionConfiguration);

        $response = $controller->openApiJson($this->request());

        $document = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([['url' => '/api/']], $document['servers']);
    }

    #[Test]
    public function docsThrowsA404ProblemWhenTheFlagIsDisabled(): void
    {
        $controller = $this->controller('0');

        $this->expectException(HttpProblemException::class);

        $controller->docs($this->request());
    }

    #[Test]
    public function docsRendersAnHtmlPageReferencingTheGeneratedSpecUrl(): void
    {
        $controller = $this->controller('1');

        $response = $controller->docs($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));

        $html = (string) $response->getBody();
        self::assertStringContainsString('swagger-ui-dist', $html);
        self::assertStringContainsString('"/api/example/openapi.json"', $html);
    }

    private function controller(string $swaggerUiFlag): SwaggerUiController
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn (string $extension, string $key): string => 'swaggerUi' === $key ? $swaggerUiFlag : '/api/',
        );

        return new SwaggerUiController(new OpenApiGenerator($this->registry()), $this->urlGenerator(), $extensionConfiguration);
    }

    private function urlGenerator(): RouteUrlGenerator
    {
        return new RouteUrlGenerator($this->registry(), new SiteBasePathResolver());
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest('https://example.com/'))->withAttribute('site', new Site('main', 1, ['base' => 'https://example.com/']));
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'example_count' => ['path' => '/api/example', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'routing_swagger_openapi_json' => ['path' => '/api/example/openapi.json', 'methods' => ['GET'], 'controller' => SwaggerUiController::class.'::openApiJson', 'env' => 'Development', 'requirements' => []],
            'routing_swagger_docs' => ['path' => '/api/example/docs', 'methods' => ['GET'], 'controller' => SwaggerUiController::class.'::docs', 'env' => 'Development', 'requirements' => []],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }
}
