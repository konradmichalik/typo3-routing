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

namespace KonradMichalik\Typo3Routing\RateLimit;

use KonradMichalik\Typo3Routing\Http\JsonErrorResponse;
use Psr\Http\Message\ResponseInterface;

use function max;
use function time;

/**
 * RateLimitCheck.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RateLimitCheck
{
    public function __construct(
        private RateLimitEnforcer $enforcer,
    ) {}

    /**
     * Consumes a token and reports both the outcome and the quota headers to stamp on the eventual
     * response — accepted or blocked, a client can always see its remaining quota.
     *
     * @param array{limit: int, interval: string, policy: string, keyBy: string} $config
     *
     * @return array{blocked: ResponseInterface|null, headers: array<string, string>}
     */
    public function evaluate(string $routeName, array $config, string $clientId): array
    {
        $result = $this->enforcer->consume($routeName, $config, $clientId);
        $headers = [
            'X-RateLimit-Limit' => (string) $result->getLimit(),
            'X-RateLimit-Remaining' => (string) $result->getRemainingTokens(),
            'X-RateLimit-Reset' => (string) $result->getRetryAfter()->getTimestamp(),
        ];

        if ($result->isAccepted()) {
            return ['blocked' => null, 'headers' => $headers];
        }

        $blocked = JsonErrorResponse::create(429, 'Too Many Requests', [
            'Retry-After' => (string) max(0, $result->getRetryAfter()->getTimestamp() - time()),
        ]);

        return ['blocked' => $blocked, 'headers' => $headers];
    }
}
