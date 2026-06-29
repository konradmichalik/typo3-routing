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

use function is_array;
use function json_decode;
use function str_contains;
use function strtolower;

/**
 * RequestBody.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RequestBody
{
    /**
     * The request body as an associative array. TYPO3 only populates the parsed body for
     * form-encoded POST requests, so a JSON payload — and any PUT/PATCH body — is decoded
     * from the raw stream here, letting it bind to typed arguments like a form field would.
     *
     * @return array<string, mixed>
     */
    public static function toArray(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && [] !== $parsed) {
            return $parsed;
        }

        if (!self::isJson($request)) {
            return [];
        }

        $decoded = json_decode(self::readRaw($request), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function isJson(ServerRequestInterface $request): bool
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        return str_contains($contentType, 'application/json') || str_contains($contentType, '+json');
    }

    private static function readRaw(ServerRequestInterface $request): string
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $raw = $stream->getContents();

        // Leave the stream rewound so a controller that injects the request can still read the body.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $raw;
    }
}
