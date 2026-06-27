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
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Param
{
    /**
     * @param string|null $name   Input/path key to read instead of the parameter name
     * @param string|null $source Pin the value source: "path", "query", "body" or "input" (query + body)
     */
    public function __construct(
        public ?string $name = null,
        public ?string $source = null,
    ) {}
}
