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

use DateTimeImmutable;
use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * UnsupportedArgumentController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UnsupportedArgumentController implements RouteControllerInterface
{
    #[Route(path: '/api/unsupported', name: 'unsupported_object')]
    public function object(DateTimeImmutable $when): JsonResponse
    {
        return new JsonResponse(['when' => $when->format('c')]);
    }
}
