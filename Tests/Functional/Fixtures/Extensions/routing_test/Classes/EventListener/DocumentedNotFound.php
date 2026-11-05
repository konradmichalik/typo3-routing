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

namespace KonradMichalik\RoutingTest\EventListener;

use KonradMichalik\Typo3Routing\Event\UnmatchedRouteResponseEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\JsonResponse;

use function str_starts_with;

/**
 * Answers unmatched paths under /api/documented/ with a documentation link, leaving every other path
 * to the default 404.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsEventListener(identifier: 'routing-test/documented-not-found')]
final class DocumentedNotFound
{
    public function __invoke(UnmatchedRouteResponseEvent $event): void
    {
        if (str_starts_with($event->getPath(), '/api/documented/')) {
            $event->setResponse(new JsonResponse(['error' => 'No endpoint at '.$event->getPath(), 'documentation' => 'https://example.com/docs'], 404));
        }
    }
}
