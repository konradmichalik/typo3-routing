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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Fixtures;

use KonradMichalik\Typo3Routing\Attribute\{Param, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * ParamContributionController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ParamContributionController implements RouteControllerInterface
{
    // The #[Param] requirement and the PHP default are hoisted into the route's requirements/defaults.
    #[Route(path: '/api/blog/{page}', name: 'param_blog')]
    public function blog(#[Param(requirement: '\d+', description: 'Page number, 1-based.')] int $page = 1): JsonResponse
    {
        return new JsonResponse(['page' => $page]);
    }

    // Wire name from #[Param(name:)] keys the hoisted requirement, not the parameter name.
    #[Route(path: '/api/search', name: 'param_search')]
    public function search(#[Param(name: 'q', requirement: '\w+')] string $term): JsonResponse
    {
        return new JsonResponse(['term' => $term]);
    }
}
