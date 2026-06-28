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

use KonradMichalik\Typo3Routing\Attribute\{Authenticate, RequireRequestToken, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\{DenyAuthenticator, PassAuthenticator};
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AuthenticatedController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AuthenticatedController implements RouteControllerInterface
{
    #[Route(path: '/api/secure', name: 'fixture_secure')]
    #[Authenticate(PassAuthenticator::class)]
    #[Authenticate(DenyAuthenticator::class, options: ['role' => 'admin'])]
    public function secure(): JsonResponse
    {
        return new JsonResponse(['secure' => true]);
    }

    #[Route(path: '/api/account/update', methods: ['POST'], name: 'fixture_account_update')]
    #[Authenticate(PassAuthenticator::class)]
    #[RequireRequestToken(scope: 'routing/account-update')]
    public function update(): JsonResponse
    {
        return new JsonResponse(['updated' => true]);
    }

    #[Route(path: '/api/token-default', methods: ['POST'], name: 'fixture_token_default')]
    #[RequireRequestToken]
    public function tokenDefault(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
