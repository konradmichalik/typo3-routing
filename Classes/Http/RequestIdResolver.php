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

use function bin2hex;
use function chr;
use function ord;
use function random_bytes;
use function sprintf;
use function str_split;
use function trim;

/**
 * RequestIdResolver.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RequestIdResolver
{
    private const HEADER = 'X-Request-ID';

    /**
     * Stamps every attribute-route response with a correlation id: the incoming header value when the
     * client already sent one, otherwise a freshly generated UUIDv4 — so a single id ties a client's
     * request to this response even across proxies that generate their own.
     */
    public static function decorate(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        return $response->withHeader(self::HEADER, self::resolve($request));
    }

    private static function resolve(ServerRequestInterface $request): string
    {
        $incoming = trim($request->getHeaderLine(self::HEADER));

        return '' !== $incoming ? $incoming : self::generate();
    }

    private static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        /** @var non-empty-list<non-empty-string> $groups */
        $groups = str_split(bin2hex($bytes), 4);

        return sprintf('%s%s-%s-%s-%s-%s%s%s', ...$groups);
    }
}
