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

use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

use function gmdate;
use function implode;
use function sprintf;

/**
 * DeprecationHeaders.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DeprecationHeaders
{
    public function __construct(
        private RouteRegistry $registry,
        private RouteUrlGenerator $urlGenerator,
    ) {}

    /**
     * Stamped on every response the matched route produces — success, a conditional 304, or any 4xx
     * from further down the gauntlet — never before the response cache write, so a cache hit still
     * carries it and a container rebuild after editing the attribute is never served a stale value.
     * A no-op for a route without #[DeprecatedRoute], or when no route matched at all.
     */
    public function decorate(ResponseInterface $response, ServerRequestInterface $request, ?string $routeName): ResponseInterface
    {
        if (null === $routeName) {
            return $response;
        }

        $deprecation = $this->registry->getDeprecation($routeName);
        if (null === $deprecation) {
            return $response;
        }

        // RFC 9745 section 2: an Item Structured Field Date, "@" plus a Unix timestamp — never an
        // HTTP-date, which is the standard implementation mistake early drafts of the RFC invited.
        $response = $response->withHeader('Deprecation', '@'.$deprecation['since']);

        if (null !== $deprecation['sunset']) {
            // RFC 8594 section 3: an HTTP-date (IMF-fixdate), a different format for historical reasons.
            $response = $response->withHeader('Sunset', gmdate('D, d M Y H:i:s', $deprecation['sunset']).' GMT');
        }

        $links = $this->links($request, $deprecation);

        return [] === $links ? $response : $response->withAddedHeader('Link', implode(', ', $links));
    }

    /**
     * @param array{since: int, sunset: int|null, successor: string|null, documentation: string|null} $deprecation
     *
     * @return list<string>
     */
    private function links(ServerRequestInterface $request, array $deprecation): array
    {
        $links = [];

        if (null !== $deprecation['successor']) {
            $links[] = sprintf('<%s>; rel="successor-version"', $this->urlGenerator->generate($request, $deprecation['successor']));
        }

        if (null !== $deprecation['documentation']) {
            $links[] = sprintf('<%s>; rel="deprecation"', $deprecation['documentation']);
        }

        return $links;
    }
}
