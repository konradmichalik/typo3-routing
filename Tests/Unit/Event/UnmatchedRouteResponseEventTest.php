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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Event;

use KonradMichalik\Typo3Routing\Event\UnmatchedRouteResponseEvent;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};

/**
 * UnmatchedRouteResponseEventTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(UnmatchedRouteResponseEvent::class)]
final class UnmatchedRouteResponseEventTest extends TestCase
{
    #[Test]
    public function carriesTheRequestAndPathAndNoResponseUntilOneIsSet(): void
    {
        $request = new ServerRequest('https://example.com/api/missing');
        $event = new UnmatchedRouteResponseEvent($request, '/api/missing');

        self::assertSame($request, $event->getRequest());
        self::assertSame('/api/missing', $event->getPath());
        self::assertNull($event->getResponse());

        $response = new Response();
        $event->setResponse($response);

        self::assertSame($response, $event->getResponse());
    }
}
