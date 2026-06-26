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
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /**
     * @param list<string>          $methods      Allowed HTTP methods (upper-case)
     * @param string|null           $name         Explicit route name; auto-derived from service id + method when null
     * @param string|null           $env          Top-level application context this route is bound to (e.g. "Development"); null = always active
     * @param array<string, string> $requirements Constraints by parameter name → regex. A name matching a path placeholder ({id}) is enforced by the matcher (404). Any other name is a required query/body parameter validated at dispatch (400; '' = presence only). E.g. ['id' => '\d+', 'q' => '']
     */
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null,
        public ?string $env = null,
        public array $requirements = [],
    ) {}
}
