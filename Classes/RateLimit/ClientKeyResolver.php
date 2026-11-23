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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\NormalizedParams;

use function inet_ntop;
use function inet_pton;
use function is_string;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * ClientKeyResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ClientKeyResolver
{
    public function __construct(
        private Context $context,
    ) {}

    /**
     * The rate-limiter bucket key for a request. `keyBy: 'user'` throttles per logged-in frontend
     * user; anonymous requests (and `keyBy: 'ip'`) fall back to the client IP. The `user:`/`ip:`
     * prefixes keep the two namespaces from colliding (a user id and an IP could look alike).
     *
     * @param array{limit: int, interval: string, policy: string, keyBy: string} $config
     */
    public function resolve(array $config, ServerRequestInterface $request): string
    {
        if ('user' === $config['keyBy']) {
            $aspect = $this->context->getAspect('frontend.user');
            if ($aspect->isLoggedIn()) {
                return 'user:'.$aspect->get('id');
            }
        }

        return 'ip:'.$this->normalizeIp($this->clientIp($request));
    }

    /**
     * An IPv6 client usually controls a whole /64, so keying by the full address would hand it a fresh
     * bucket per request. IPv6 keys by its canonical /64 prefix, IPv4-mapped IPv6 by the IPv4 address.
     * IPv4 and unparseable values are returned unchanged.
     */
    private function normalizeIp(string $ip): string
    {
        $packed = inet_pton($ip);
        if (false === $packed || 16 !== strlen($packed)) {
            return $ip;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        // normalizedParams is set early in the frontend stack and resolves reverse-proxy headers.
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams->getRemoteAddress();
        }

        $remoteAddress = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($remoteAddress) ? $remoteAddress : '';
    }
}
