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

use KonradMichalik\Typo3Routing\Attribute\{Returns, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Dto\CourseDto;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * DuplicateReturnsStatusController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DuplicateReturnsStatusController implements RouteControllerInterface
{
    #[Route(path: '/api/duplicate-returns', name: 'duplicate_returns')]
    #[Returns(CourseDto::class, status: 200)]
    #[Returns(status: 200, description: 'Also 200')]
    public function action(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
