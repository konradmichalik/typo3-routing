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

use function array_is_list;
use function in_array;
use function is_array;
use function json_decode;
use function json_last_error;
use function str_contains;
use function strtolower;

use const JSON_ERROR_NONE;

/**
 * RequestBody.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RequestBody
{
    /**
     * Methods for which TYPO3's ServerRequestFactory::fromGlobals() runs the raw body through
     * parse_str() and calls withParsedBody() whenever the result is non-empty. A JSON payload
     * has no `=`/`&`, so parse_str() turns it into a single garbage key (e.g. `{"n":1}` => ''),
     * which is non-empty and would otherwise be trusted as the real parsed body.
     *
     * @var list<string>
     */
    private const CORE_FORM_DECODED_METHODS = ['PUT', 'PATCH', 'DELETE'];

    /**
     * Per-request memo of the decoded body, keyed by the body stream. The stream instance is shared
     * across the request clones that withAttribute() produces, so the three consumers in one request —
     * the dispatcher's body-shape check, its input-requirement check, and the argument resolver —
     * decode a payload once, not three times. Entries are released with the stream (weak references),
     * so nothing leaks across requests.
     *
     * @var WeakMap<StreamInterface, array{body: array<string, mixed>, error: string|null}>|null
     */
    private static ?WeakMap $decoded = null;

    /**
     * The request body as an associative array. TYPO3 only populates the parsed body for
     * form-encoded POST requests, so a JSON payload — and any PUT/PATCH body — is decoded
     * from the raw stream here, letting it bind to typed arguments like a form field would.
     *
     * A malformed or wrong-shaped JSON body decodes to [] here, same as an absent one — the response
     * naming the real cause is decodeErrorDetail()'s job, checked separately before this ever runs.
     *
     * @return array<string, mixed>
     */
    public static function toArray(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        $isCoreFormDecodedJson = self::isJson($request)
            && in_array($request->getMethod(), self::CORE_FORM_DECODED_METHODS, true);
        if (is_array($parsed) && [] !== $parsed && !$isCoreFormDecodedJson) {
            return $parsed;
        }

        if (!self::isJson($request)) {
            return [];
        }

        return self::decode($request)['body'];
    }

    /**
     * Null for an absent, empty, or valid JSON-object body — the shapes toArray() already handles
     * silently. Set when the JSON is syntactically malformed or decodes to something other than an
     * object (a scalar, or a JSON array), which cannot bind to named parameters at all.
     */
    public static function decodeErrorDetail(ServerRequestInterface $request): ?string
    {
        if (!self::isJson($request)) {
            return null;
        }

        return self::decode($request)['error'];
    }

    /**
     * True when a non-empty body arrived under a content type this extension cannot read at all —
     * neither JSON nor the form encodings TYPO3 core already parses into getParsedBody(). Checked
     * against the header directly, not getParsedBody(), because core's own parse_str() fallback for
     * PUT/PATCH/DELETE (see CORE_FORM_DECODED_METHODS) can populate a parsed body from a payload that
     * was never actually form-encoded.
     */
    public static function isUnsupportedMediaType(ServerRequestInterface $request): bool
    {
        if (self::isJson($request) || self::isFormEncoded($request)) {
            return false;
        }

        return '' !== self::readRaw($request);
    }

    /**
     * @return array{body: array<string, mixed>, error: string|null}
     */
    private static function decode(ServerRequestInterface $request): array
    {
        $stream = $request->getBody();
        $memo = self::$decoded ??= new WeakMap();
        if (isset($memo[$stream])) {
            return $memo[$stream];
        }

        $raw = self::readRaw($request);
        if ('' === $raw) {
            // Not malformed: an absent body is a valid, empty one.
            return $memo[$stream] = ['body' => [], 'error' => null];
        }

        $decoded = json_decode($raw, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            return $memo[$stream] = ['body' => [], 'error' => 'Malformed JSON request body'];
        }
        // A literal JSON `null` carries no data, same as an absent body.
        if (null === $decoded) {
            return $memo[$stream] = ['body' => [], 'error' => null];
        }
        if (is_array($decoded) && ([] === $decoded || !array_is_list($decoded))) {
            /** @var array<string, mixed> $body */
            $body = $decoded;

            return $memo[$stream] = ['body' => $body, 'error' => null];
        }

        return $memo[$stream] = ['body' => [], 'error' => 'JSON request body must be a JSON object'];
    }

    private static function isJson(ServerRequestInterface $request): bool
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        return str_contains($contentType, 'application/json') || str_contains($contentType, '+json');
    }

    private static function isFormEncoded(ServerRequestInterface $request): bool
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        return str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data');
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
