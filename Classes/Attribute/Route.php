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
 * Route.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /**
     * On a controller method this defines an endpoint. On the controller class it defines a prefix
     * applied to every method route: `path` is prepended to each method path, `name` is prepended to
     * each resolved route name, `env` becomes the default for methods that do not set their own, and
     * `requirements` are merged under the method requirements (the method wins per key). A class-level
     * `methods` is ignored — the method default ['GET'] is indistinguishable from "not set", so HTTP
     * methods are never inherited. At most one #[Route] is allowed on the class.
     *
     * @param list<string>          $methods         Allowed HTTP methods (upper-case). Ignored at class level.
     * @param string|null           $name            Explicit route name; auto-derived from service id + method when null. At class level: name prefix.
     * @param string|null           $env             Top-level application context this route is bound to (e.g. "Development"); null = always active. At class level: default for methods without their own env.
     * @param array<string, string> $requirements    Constraints by parameter name → regex. A name matching a path placeholder ({id}) is enforced by the matcher (404). Any other name is a required query/body parameter validated at dispatch (400; '' = presence only). E.g. ['id' => '\d+', 'q' => '']. Named patterns from Symfony\Component\Routing\Requirement\Requirement may be used as values, e.g. ['id' => Requirement::DIGITS]. At class level: merged under method requirements.
     * @param int                   $priority        Match priority; higher values are matched first. Use to disambiguate a static path from an overlapping placeholder path. Default 0
     * @param array<string, mixed>  $defaults        Default values for path placeholders. A trailing placeholder with a default becomes optional (`/blog/{page}` + ['page' => 1] also matches `/blog`, yielding page=1) and is omitted from generated URLs when it equals the default. Keys starting with "_" are reserved (used internally) and rejected at build time. At class level: merged under method defaults (the method wins per key).
     * @param list<string>          $schemes         Allowed URI schemes (e.g. ['https']); empty = any scheme. A request whose scheme doesn't match yields the same 404 as a path mismatch. Not inherited from a class-level #[Route] (same rule as `methods`).
     * @param string|null           $host            Restrict the route to a specific hostname (e.g. 'api.example.com'); null = any host. A request from a different host yields the same 404 as a path mismatch. Not inherited from a class-level #[Route] (same rule as `methods`).
     * @param string|null           $description     Human-readable summary of what the endpoint does, surfaced in `routing:debug` and the OpenAPI export. At class level: fallback used by methods that do not set their own.
     * @param bool|null             $caseInsensitive Match the path's and, when set, the `host`'s literal segments regardless of case, so /api/Example also answers /API/EXAMPLE. Placeholder VALUES keep their original case and their `requirements` stay case-sensitive. Only consulted after the exact path already failed, so nothing else pays for it. null = not set, inheriting the class-level value (default: case-sensitive). At class level: default for methods without their own.
     * @param list<string>|null     $tags            OpenAPI operation tags, grouping endpoints in Swagger UI and client generators. null/empty falls back to the controller's service id, today's default. null = not set, inheriting the class-level value. At class level: default for methods without their own.
     * @param bool|null             $exclusive       Claim this class's own route prefix exclusively: a request under it that matches none of the class's routes gets a JSON 404 instead of falling through to page rendering. Meaningful only on a class-level #[Route]; setting it on a method-level #[Route] is a build-time error. null = not set (default: not exclusive).
     * @param bool|null             $canonical       When a request only matched a tolerated variant of this path (trailing slash, or case via `caseInsensitive`), answer `308 Permanent Redirect` to the declared path instead of serving the response directly. `308` preserves method and body. Has no effect on a request that matched the exact declared path. null = not set, inheriting the class-level value (default: answer directly, no redirect). At class level: default for methods without their own.
     * @param list<string>|null     $sites           Site identifiers (as configured per-site in config.yaml under config/sites) this route is reachable from; null/empty = every site. Out of scope yields the same 404 as an unmatched path. An unknown identifier is never rejected at build time (site configuration is not reliably readable while the container builds, and would go stale the moment a site is renamed) — it is reported at runtime and by `routing:lint` instead. null = not set, inheriting the class-level value. At class level: default for methods without their own.
     * @param list<int>|null        $languages       Language ids this route is reachable in; null/empty = every language. Out of scope yields the same 404 as an unmatched path. null = not set, inheriting the class-level value. At class level: default for methods without their own.
     * @param list<string>          $aliases         Alternate name(s) this route also resolves under for URL generation (RouteUrlGenerator, `{routing:uri}`/`{routing:uris}`). Never matches a request path, and never appears in `routing:debug` or the OpenAPI export as a route of its own. An alias colliding with an existing route name, or declared by two routes, fails the container build. At class level: prefixed the same way `name` is.
     */
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null,
        public ?string $env = null,
        public array $requirements = [],
        public int $priority = 0,
        public array $defaults = [],
        public array $schemes = [],
        public ?string $host = null,
        public ?string $description = null,
        public ?bool $caseInsensitive = null,
        public ?array $tags = null,
        public ?bool $exclusive = null,
        public ?bool $canonical = null,
        public ?array $sites = null,
        public ?array $languages = null,
        public array $aliases = [],
    ) {}
}
