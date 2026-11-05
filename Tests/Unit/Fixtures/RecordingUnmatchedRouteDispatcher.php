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

use KonradMichalik\Typo3Routing\Event\UnmatchedRouteResponseEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for a listener: records every unmatched path and answers with $response when one is set.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RecordingUnmatchedRouteDispatcher implements EventDispatcherInterface
{
    public ?ResponseInterface $response = null;

    /** @var list<string> */
    public array $paths = [];

    public function dispatch(object $event): object
    {
        if ($event instanceof UnmatchedRouteResponseEvent) {
            $this->paths[] = $event->getPath();
            if (null !== $this->response) {
                $event->setResponse($this->response);
            }
        }

        return $event;
    }
}
