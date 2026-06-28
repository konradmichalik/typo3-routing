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
     * @param array<string, string> $headers
     */
    public static function create(int $status, string $message, array $headers = []): Response
    {
        $response = new Response('php://temp', $status, array_merge(['Content-Type' => 'application/json'], $headers));
        $response->getBody()->write(json_encode(['error' => $message, 'status' => $status], \JSON_THROW_ON_ERROR));

        return $response;
    }
}
