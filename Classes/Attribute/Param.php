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
 * Param.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Param
{
    /**
     * @param string|null    $name        Input/path key to read instead of the parameter name
     * @param string|null    $source      Pin the value source: "path", "query", "body" or "input" (query + body)
     * @param string|null    $requirement Regex the value must satisfy, hoisted into the route's requirements
     * @param string|null    $description Human-readable summary of the parameter, surfaced in the OpenAPI export
     * @param int|float|null $min         Smallest accepted value (inclusive) of an int or float parameter
     * @param int|float|null $max         Largest accepted value (inclusive) of an int or float parameter
     * @param int|null       $minLength   Fewest characters (not bytes) a string parameter accepts
     * @param int|null       $maxLength   Most characters (not bytes) a string parameter accepts
     */
    public function __construct(
        public ?string $name = null,
        public ?string $source = null,
        public ?string $requirement = null,
        public ?string $description = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
    ) {}
}
