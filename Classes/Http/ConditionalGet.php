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

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Core\Http\Response;

use function explode;
use function str_starts_with;
use function substr;
use function trim;

/**
 * ConditionalGet.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ConditionalGet
{
    /**
     * Returns a 304 Not Modified when the response carries an ETag the request's If-None-Match
     * validator matches, otherwise null so the caller sends the full response.
     */
    public static function notModified(ServerRequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
        $etag = $response->getHeaderLine('ETag');
        if ('' === $etag || !self::ifNoneMatchSatisfied($request->getHeaderLine('If-None-Match'), $etag)) {
            return null;
        }

        // RFC 9110: a 304 echoes the validator and omits the body.
        return new Response('php://temp', 304, ['ETag' => $etag]);
    }

    private static function ifNoneMatchSatisfied(string $header, string $etag): bool
    {
        if ('' === $header) {
            return false;
        }
        if ('*' === trim($header)) {
            return true;
        }

        // Weak comparison: a strong and a weak tag with the same opaque value match (RFC 9110 §8.8.3.2).
        $normalize = static fn (string $tag): string => str_starts_with($trimmed = trim($tag), 'W/') ? substr($trimmed, 2) : $trimmed;
        $current = $normalize($etag);
        foreach (explode(',', $header) as $candidate) {
            if ($normalize($candidate) === $current) {
                return true;
            }
        }

        return false;
    }
}
