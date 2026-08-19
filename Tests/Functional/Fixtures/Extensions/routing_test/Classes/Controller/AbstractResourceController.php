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

namespace KonradMichalik\RoutingTest\Controller;

use KonradMichalik\Typo3Routing\Attribute\{Param, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AbstractResourceController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
abstract class AbstractResourceController implements RouteControllerInterface
{
    #[Route(path: '', name: 'list')]
    final public function list(#[Param(requirement: '\d+')] int $page = 1): JsonResponse
    {
        return new JsonResponse(['resource' => $this->resource(), 'page' => $page]);
    }

    #[Route(path: '/{uid}', name: 'detail', requirements: ['uid' => '\d+'])]
    final public function detail(int $uid): JsonResponse
    {
        return new JsonResponse(['resource' => $this->resource(), 'uid' => $uid]);
    }

    abstract protected function resource(): string;
}
