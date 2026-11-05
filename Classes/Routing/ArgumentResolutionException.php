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

use RuntimeException;

use function sprintf;

/**
 * ArgumentResolutionException.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ArgumentResolutionException extends RuntimeException
{
    public static function invalidValue(string $name, int $code): self
    {
        return new self(sprintf('Invalid value for parameter: %s', $name), $code);
    }
}
