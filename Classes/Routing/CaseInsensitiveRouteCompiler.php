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

use Symfony\Component\Routing\{CompiledRoute, Route, RouteCompiler};

/**
 * CaseInsensitiveRouteCompiler.
 *
 * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CaseInsensitiveRouteCompiler extends RouteCompiler
{
    public static function compile(Route $route): CompiledRoute
    {
        $compiled = parent::compile($route);
        $hostRegex = $compiled->getHostRegex();

        return new CompiledRoute(
            '',
            $compiled->getRegex().'i',
            $compiled->getTokens(),
            $compiled->getPathVariables(),
            null === $hostRegex ? null : $hostRegex.'i',
            $compiled->getHostTokens(),
            $compiled->getHostVariables(),
            $compiled->getVariables(),
        );
    }
}
