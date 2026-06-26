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
use KonradMichalik\Typo3Routing\ViewHelpers\UrisViewHelper;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;


/**
 * UrisViewHelperTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

#[CoversClass(UrisViewHelper::class)]
final class UrisViewHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function rendersAJsonMapOfTheNamedRoutes(): void
    {
        $this->registerGenerator();
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/'))
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://example.com/']));

        $viewHelper = new UrisViewHelper();
        $viewHelper->setArguments(['routes' => ['count' => 'example_count', 'list' => 'example_list']]);

        self::assertJsonStringEqualsJsonString(
            '{"count":"/api/count","list":"/api/list"}',
            $viewHelper->render(),
        );
    }

    #[Test]
    public function registersTheRoutesArgument(): void
    {
        $arguments = (new UrisViewHelper())->prepareArguments();

        self::assertArrayHasKey('routes', $arguments);
    }

    #[Test]
    public function throwsWhenNoServerRequestIsAvailable(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        $viewHelper = new UrisViewHelper();
        $viewHelper->setArguments(['routes' => ['count' => 'example_count']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1750000003);

        $viewHelper->render();
    }

    private function registerGenerator(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'example_count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'example_list' => ['path' => '/api/list', 'methods' => ['GET'], 'controller' => 'ctrl::list', 'env' => null, 'requirements' => []],
        ];
        $generator = new RouteUrlGenerator(new RouteRegistry($routes, new ServiceLocator([])), new SiteBasePathResolver());

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with(RouteUrlGenerator::class)->willReturn($generator);

        GeneralUtility::setContainer($container);
    }
}
