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

namespace KonradMichalik\Typo3Routing\Attribute;

use Attribute;

/**
 * DeprecatedRoute.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class DeprecatedRoute
{
    /**
     * Marks the route as deprecated (or, at class level, every method route without its own
     * #[DeprecatedRoute]) — feeds the `Deprecation`/`Sunset`/`Link` response headers (RFC 9745 /
     * RFC 8594), `deprecated: true` in the OpenAPI export, and `routing:debug`. The method's own
     * attribute wins entirely over the class-level one; it is not merged field by field.
     *
     * @param string      $since         When the route became deprecated. Any format `DateTimeImmutable` accepts (e.g. '2026-03-01'); rejected at build time if unparseable.
     * @param string|null $sunset        When the route stops being supported, same format as `since`. Must not precede `since` — rejected at build time, naming the route.
     * @param string|null $successor     Route name replacing this one; resolved through `RouteUrlGenerator` into the `Link: rel="successor-version"` header. An unknown route name fails the build.
     * @param string|null $documentation URL with migration guidance; emitted as `Link: rel="deprecation"` when given
     */
    public function __construct(
        public string $since,
        public ?string $sunset = null,
        public ?string $successor = null,
        public ?string $documentation = null,
    ) {}
}
