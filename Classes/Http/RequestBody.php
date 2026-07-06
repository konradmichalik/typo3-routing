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

use Psr\Http\Message\{ServerRequestInterface, StreamInterface};
use WeakMap;

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
     * Per-request memo of the decoded body, keyed by the body stream. The stream instance is shared
     * across the request clones that withAttribute() produces, so the two consumers in one request —
     * the dispatcher's input-requirement check and the argument resolver — decode a payload once, not
     * twice. Entries are released with the stream (weak references), so nothing leaks across requests.
     *
     * @var WeakMap<StreamInterface, array<mixed>>|null
     */
    private static ?WeakMap $decoded = null;

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

        $stream = $request->getBody();
        $memo = self::$decoded ??= new WeakMap();
        if (isset($memo[$stream])) {
            return $memo[$stream];
        }

        $decoded = self::isJson($request) ? json_decode(self::readRaw($request), true) : null;

        return $memo[$stream] = is_array($decoded) ? $decoded : [];
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
