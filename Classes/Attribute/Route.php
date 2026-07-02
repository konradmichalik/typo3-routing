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
     * @param list<string>          $methods      Allowed HTTP methods (upper-case). Ignored at class level.
     * @param string|null           $name         Explicit route name; auto-derived from service id + method when null. At class level: name prefix.
     * @param string|null           $env          Top-level application context this route is bound to (e.g. "Development"); null = always active. At class level: default for methods without their own env.
     * @param array<string, string> $requirements Constraints by parameter name → regex. A name matching a path placeholder ({id}) is enforced by the matcher (404). Any other name is a required query/body parameter validated at dispatch (400; '' = presence only). E.g. ['id' => '\d+', 'q' => '']. Named patterns from Symfony\Component\Routing\Requirement\Requirement may be used as values, e.g. ['id' => Requirement::DIGITS]. At class level: merged under method requirements.
     * @param int                   $priority     Match priority; higher values are matched first. Use to disambiguate a static path from an overlapping placeholder path. Default 0
     */
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null,
        public ?string $env = null,
        public array $requirements = [],
        public int $priority = 0,
    ) {}
}
