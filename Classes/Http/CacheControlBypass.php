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

namespace KonradMichalik\Typo3Routing\Http;

use Psr\Http\Message\ServerRequestInterface;

use function str_contains;
use function strtolower;

/**
 * CacheControlBypass.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CacheControlBypass
{
    /**
     * "no-cache" (must revalidate) and "no-store" (must not use a stored copy) both mean the response
     * cache must not be read for this request.
     */
    public static function skipsRead(ServerRequestInterface $request): bool
    {
        $directives = self::directives($request);

        return str_contains($directives, 'no-cache') || str_contains($directives, 'no-store');
    }

    /**
     * Only "no-store" forbids writing a fresh response into the cache; "no-cache" still allows the
     * cache to be refreshed for the next request.
     */
    public static function skipsWrite(ServerRequestInterface $request): bool
    {
        return str_contains(self::directives($request), 'no-store');
    }

    private static function directives(ServerRequestInterface $request): string
    {
        return strtolower($request->getHeaderLine('Cache-Control'));
    }
}
