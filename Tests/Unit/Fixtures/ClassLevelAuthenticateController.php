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

use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\{DenyAuthenticator, PassAuthenticator};
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * ClassLevelAuthenticateController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Authenticate(PassAuthenticator::class)]
#[Authenticate(DenyAuthenticator::class, options: ['role' => 'admin'])]
final class ClassLevelAuthenticateController implements RouteControllerInterface
{
    // No own #[Authenticate]: falls back to the class-level, OR-combined list.
    #[Route(path: '/api/auth/class-level', name: 'auth_class_level')]
    public function classLevel(): JsonResponse
    {
        return new JsonResponse([]);
    }

    // Own #[Authenticate] wins entirely over the class-level list, not merged.
    #[Route(path: '/api/auth/method-level', name: 'auth_method_level')]
    #[Authenticate(PassAuthenticator::class)]
    public function methodLevel(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
