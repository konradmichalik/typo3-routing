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
 * CanonicalController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/canonical', name: 'canonical_', canonical: true)]
final class CanonicalController implements RouteControllerInterface
{
    // Inherits the class-level opt-in.
    #[Route(path: '/inherited', name: 'inherited')]
    public function inherited(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['inherited' => true]);
    }

    // Opts back out despite the class-level opt-in.
    #[Route(path: '/opted-out', name: 'optedOut', canonical: false)]
    public function optedOut(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['optedOut' => true]);
    }
}
