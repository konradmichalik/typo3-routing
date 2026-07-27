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
 * Cors.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Cors
{
    /**
     * Overrides the global CORS configuration entirely for this route (or, at class level, every
     * method route without its own #[Cors]) — it is not merged with the global settings.
     *
     * @param list<string> $allowedOrigins   Origins allowed to call this route, or ['*'] for any. Rejected at build time together with allowCredentials: true.
     * @param bool         $allowCredentials Allow credentialed requests (cookies, Authorization header). Requires an explicit origin list.
     * @param string       $allowedHeaders   Comma-separated request headers a client may send (Access-Control-Allow-Headers)
     * @param string       $exposeHeaders    Comma-separated response headers exposed to the browser (Access-Control-Expose-Headers)
     * @param int          $maxAge           How long (seconds) the browser may cache the preflight result (Access-Control-Max-Age)
     */
    public function __construct(
        public array $allowedOrigins,
        public bool $allowCredentials = false,
        public string $allowedHeaders = 'Content-Type, Authorization',
        public string $exposeHeaders = '',
        public int $maxAge = 3600,
    ) {}
}
