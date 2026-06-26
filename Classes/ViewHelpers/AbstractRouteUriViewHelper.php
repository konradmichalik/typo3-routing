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

namespace KonradMichalik\Typo3Routing\ViewHelpers;

use KonradMichalik\Typo3Routing\Http\RouteUrlGenerator;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * AbstractRouteUriViewHelper.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
abstract class AbstractRouteUriViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    protected function resolveRequest(): ?ServerRequestInterface
    {
        // $GLOBALS['TYPO3_REQUEST'] is the current PSR-7 request in both v13 and v14 and carries the
        // resolved site/language context — unlike the rendering context's request accessor, whose API
        // differs between the two versions.
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        return $request instanceof ServerRequestInterface ? $request : null;
    }

    protected function urlGenerator(): RouteUrlGenerator
    {
        return GeneralUtility::getContainer()->get(RouteUrlGenerator::class);
    }
}
