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

namespace KonradMichalik\Typo3Routing\Tests\Unit\ViewHelpers;

use KonradMichalik\Typo3Routing\Http\{RouteUrlGenerator, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use KonradMichalik\Typo3Routing\ViewHelpers\UriViewHelper;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * UriViewHelperTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(UriViewHelper::class)]
final class UriViewHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function rendersReachableUriIncludingSiteBase(): void
    {
        $this->registerGenerator();
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/sub/'))
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://example.com/sub/']));

        $viewHelper = new UriViewHelper();
        $viewHelper->setArguments(['route' => 'example_count', 'parameters' => []]);

        self::assertSame('/sub/api/count', $viewHelper->render());
    }

    #[Test]
    public function registersRouteAndParameterArguments(): void
    {
        $arguments = (new UriViewHelper())->prepareArguments();

        self::assertArrayHasKey('route', $arguments);
        self::assertArrayHasKey('parameters', $arguments);
    }

    #[Test]
    public function throwsWhenNoServerRequestIsAvailable(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        $viewHelper = new UriViewHelper();
        $viewHelper->setArguments(['route' => 'example_count', 'parameters' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1750000001);

        $viewHelper->render();
    }

    private function registerGenerator(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'example_count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
        ];
        $generator = new RouteUrlGenerator(new RouteRegistry($routes, new ServiceLocator([])), new SiteBasePathResolver());

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with(RouteUrlGenerator::class)->willReturn($generator);

        GeneralUtility::setContainer($container);
    }
}
