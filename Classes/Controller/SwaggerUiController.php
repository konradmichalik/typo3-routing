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

namespace KonradMichalik\Typo3Routing\Controller;

use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Http\{HttpProblemException, RouteUrlGenerator};
use KonradMichalik\Typo3Routing\OpenApi\OpenApiGenerator;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\{HtmlResponse, JsonResponse};

use function is_string;
use function json_encode;

/**
 * SwaggerUiController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SwaggerUiController implements RouteControllerInterface
{
    public function __construct(
        private OpenApiGenerator $generator,
        private RouteUrlGenerator $urlGenerator,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    #[Route(path: '/api/_routing/openapi.json', name: 'routing_swagger_openapi_json', env: 'Development')]
    public function openApiJson(ServerRequestInterface $request): JsonResponse
    {
        $this->assertEnabled();

        return new JsonResponse($this->generator->generate('TYPO3 Routing API', '1.0.0', $this->serverPrefix()));
    }

    #[Route(path: '/api/_routing/docs', name: 'routing_swagger_docs', env: 'Development')]
    public function docs(ServerRequestInterface $request): HtmlResponse
    {
        $this->assertEnabled();

        $specUrl = $this->urlGenerator->generate($request, 'routing_swagger_openapi_json');

        return new HtmlResponse($this->renderHtml($specUrl));
    }

    private function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new HttpProblemException(404, 'Not Found');
        }
    }

    private function isEnabled(): bool
    {
        try {
            $value = $this->extensionConfiguration->get('typo3_routing', 'swaggerUi');
        } catch (Throwable) {
            return false;
        }

        return '1' === (string) $value;
    }

    private function serverPrefix(): string
    {
        try {
            $prefix = $this->extensionConfiguration->get('typo3_routing', 'prefix');

            return is_string($prefix) ? $prefix : '/api/';
        } catch (Throwable) {
            return '/api/';
        }
    }

    private function renderHtml(string $specUrl): string
    {
        $encodedSpecUrl = json_encode($specUrl, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <title>TYPO3 Routing API &mdash; Swagger UI</title>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
            </head>
            <body>
            <div id="swagger-ui"></div>
            <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
            <script>
            window.onload = function () {
                window.ui = SwaggerUIBundle({
                    url: {$encodedSpecUrl},
                    dom_id: '#swagger-ui',
                });
            };
            </script>
            </body>
            </html>
            HTML;
    }
}
