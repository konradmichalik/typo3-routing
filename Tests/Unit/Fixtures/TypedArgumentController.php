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

use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * TypedArgumentController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TypedArgumentController implements RouteControllerInterface
{
    #[Route(path: '/api/typed/{id}', name: 'typed_mixed')]
    public function mixed(int $id, string $q, bool $active = false, ?ServerRequestInterface $request = null): JsonResponse
    {
        return new JsonResponse(['id' => $id, 'q' => $q, 'active' => $active]);
    }
}
