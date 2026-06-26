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

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * UrisViewHelper.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UrisViewHelper extends AbstractRouteUriViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('routes', 'array', 'Map of output key => route name', true);
    }

    public function render(): string
    {
        $request = $this->resolveRequest();
        if (!$request instanceof ServerRequestInterface) {
            throw new RuntimeException('The routing:uris ViewHelper requires a frontend server request with a resolved site context.', 1750000003);
        }

        $generator = $this->urlGenerator();
        $map = [];
        foreach ((array) $this->arguments['routes'] as $key => $routeName) {
            $map[(string) $key] = $generator->generate($request, (string) $routeName);
        }

        return json_encode($map, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
