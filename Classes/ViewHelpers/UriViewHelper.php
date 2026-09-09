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
 * Renders the URL of a single attribute route, including the current site/language base.
 *
 * @example
 * ```html
 * <a href="{routing:uri(route: 'course_search_count')}">Count</a>
 *
 * <!-- With path parameters -->
 * {routing:uri(route: 'course_search_item', parameters: '{id: 5}')}
 *
 * <!-- Absolute, prefixing the scheme and host of the current request -->
 * {routing:uri(route: 'course_search_count', absolute: 1)}
 * ```
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UriViewHelper extends AbstractRouteUriViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('route', 'string', 'Name of the attribute route', true);
        $this->registerArgument('parameters', 'array', 'Route parameters', false, []);
        $this->registerArgument('absolute', 'bool', 'Render a full absolute URL including scheme and host', false, false);
    }

    public function render(): string
    {
        $request = $this->resolveRequest();
        if (!$request instanceof ServerRequestInterface) {
            throw new RuntimeException('The routing:uri ViewHelper requires a frontend server request with a resolved site context.', 1750000001);
        }

        return $this->urlGenerator()->generate($request, (string) $this->arguments['route'], (array) $this->arguments['parameters'], (bool) $this->arguments['absolute']);
    }
}
