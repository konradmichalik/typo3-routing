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

use LogicException;
use RuntimeException;

use function sprintf;

/**
 * HttpProblemException.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class HttpProblemException extends RuntimeException
{
    /**
     * Thrown from a controller to answer with an RFC 9457 problem-details response instead of a
     * success payload: the dispatcher maps it to `application/problem+json` with the given status
     * and the message as "detail". Unexpected exceptions stay untouched and reach TYPO3's regular
     * error handling (and its logging) as before.
     *
     * @param int    $status HTTP error status code (4xx/5xx)
     * @param string $detail Problem detail; omitted from the response when it only repeats the status title
     */
    public function __construct(
        public readonly int $status,
        string $detail = '',
    ) {
        if ($status < 400 || $status > 599) {
            throw new LogicException(sprintf('HttpProblemException expects a 4xx/5xx error status code, got %d.', $status), 1750000023);
        }

        parent::__construct($detail);
    }
}
