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
 * ReturnsController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ReturnsController implements RouteControllerInterface
{
    #[Route(path: '/api/returns/course/{id}', name: 'returns_course', requirements: ['id' => '\d+'])]
    #[Returns(CourseDto::class)]
    #[Returns(status: 404, description: 'Course not found')]
    public function course(int $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }

    #[Route(path: '/api/returns/courses', name: 'returns_courses')]
    #[Returns(CourseDto::class, collection: true)]
    public function courses(): JsonResponse
    {
        return new JsonResponse([]);
    }

    #[Route(path: '/api/returns/plain', name: 'returns_plain')]
    public function plain(): JsonResponse
    {
        return new JsonResponse([]);
    }

    #[Route(path: '/api/returns/no-body', name: 'returns_no_body')]
    #[Returns(status: 204)]
    public function noBody(): JsonResponse
    {
        return new JsonResponse([], 204);
    }
}
