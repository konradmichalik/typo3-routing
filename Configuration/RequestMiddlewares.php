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

use KonradMichalik\Typo3Routing\Middleware\RouteDispatcher;

return [
    'frontend' => [
        'konradmichalik/typo3-routing/dispatcher' => [
            'target' => RouteDispatcher::class,
            // Runs after the site middleware (resolved site/language context) and after both auth
            // middlewares, so the frontend.user / backend.user context aspects and the SecurityAspect's
            // received request token are populated before the #[Authenticate] / #[RequireRequestToken]
            // checks. The core request-token middleware runs before backend-user-authentication, so it is
            // covered too. For a Bearer-only / purely public setup neither auth middleware is needed; the
            // dispatcher may then be pulled in front of them for a marginally earlier short-circuit.
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/backend-user-authentication',
                'typo3/cms-frontend/authentication',
            ],
            // Runs before page resolving so attribute endpoints never hit the page router.
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
