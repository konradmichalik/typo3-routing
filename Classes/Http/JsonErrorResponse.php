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

use TYPO3\CMS\Core\Http\Response;

use function array_merge;
use function json_encode;

/**
 * JsonErrorResponse.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class JsonErrorResponse
{
    /**
     * Human-readable summaries for the status codes this dispatcher emits (RFC 9457 "title").
     *
     * @var array<int, string>
     */
    private const TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        429 => 'Too Many Requests',
    ];

    /**
     * @param array<string, string> $headers
     */
    public static function create(int $status, string $message, array $headers = []): Response
    {
        $title = self::TITLES[$status] ?? 'Error';

        // RFC 9457: "about:blank" is the default problem type; "detail" carries the specific
        // message and is omitted when it would only repeat the generic title.
        $problem = ['type' => 'about:blank', 'title' => $title, 'status' => $status];
        if ('' !== $message && $message !== $title) {
            $problem['detail'] = $message;
        }

        $response = new Response('php://temp', $status, array_merge(['Content-Type' => 'application/problem+json'], $headers));
        $response->getBody()->write(json_encode($problem, \JSON_THROW_ON_ERROR));

        return $response;
    }
}
