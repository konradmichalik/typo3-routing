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

namespace KonradMichalik\Typo3Routing\Routing;

use Symfony\Component\Routing\Exception\ResourceNotFoundException;

use function sprintf;

/**
 * RequirementMismatchException.
 *
 * @internal a case-insensitive match that a placeholder requirement turned away again
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RequirementMismatchException extends ResourceNotFoundException
{
    // Extending the plain ResourceNotFoundException is the point: to every caller this stays an
    // ordinary miss, and the dispatcher has to keep answering the same 404 because the reason is
    // developer-facing, not client-facing. Only the diagnostics look at the type and report what was
    // rejected, because "no route matches" is misleading once a route did match.
    public function __construct(
        public readonly string $routeName,
        public readonly string $parameter,
        public readonly string $value,
        public readonly string $requirement,
    ) {
        parent::__construct(sprintf('Route "%s" matched, but value "%s" does not match the requirement "%s" for parameter "%s".', $routeName, $value, $requirement, $parameter), 1750000031);
    }
}
