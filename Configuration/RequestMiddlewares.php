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
            // Needs the resolved site/language context, hence after the site middleware.
            'after' => [
                'typo3/cms-frontend/site',
            ],
            // Runs before page resolving so attribute endpoints never hit the page router.
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
