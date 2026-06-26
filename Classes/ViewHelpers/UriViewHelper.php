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
 * UriViewHelper.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UriViewHelper extends AbstractRouteUriViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('route', 'string', 'Name of the attribute route', true);
        $this->registerArgument('parameters', 'array', 'Route parameters', false, []);
    }

    public function render(): string
    {
        $request = $this->resolveRequest();
        if (!$request instanceof ServerRequestInterface) {
            throw new RuntimeException('The routing:uri ViewHelper requires a frontend server request with a resolved site context.', 1750000001);
        }

        return $this->urlGenerator()->generate($request, (string) $this->arguments['route'], (array) $this->arguments['parameters']);
    }
}
