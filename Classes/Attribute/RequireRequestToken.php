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
 * RequireRequestToken.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequireRequestToken
{
    /**
     * @param string|null $scope token scope; defaults to "routing/<routeName>" when null
     */
    public function __construct(
        public ?string $scope = null,
    ) {}
}
