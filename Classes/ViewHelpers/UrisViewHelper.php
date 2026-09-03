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
 * Renders a JSON map of several attribute route URLs, for embedding in an inline script.
 *
 * @example
 * ```html
 * <script>
 *     window.routingUrls = {routing:uris(routes: {
 *         count: 'course_search_count',
 *         item:  'course_search_item'
 *     })};
 *     // → {"count":"/api/course-search/count","item":"/api/course-search/item"}
 * </script>
 *
 * <!-- Absolute, prefixing the scheme and host of the current request -->
 * {routing:uris(routes: {count: 'course_search_count'}, absolute: 1)}
 * ```
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UrisViewHelper extends AbstractRouteUriViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('routes', 'array', 'Map of output key => route name', true);
        $this->registerArgument('absolute', 'bool', 'Render full absolute URLs including scheme and host', false, false);
    }

    public function render(): string
    {
        $request = $this->resolveRequest();
        if (!$request instanceof ServerRequestInterface) {
            throw new RuntimeException('The routing:uris ViewHelper requires a frontend server request with a resolved site context.', 1750000003);
        }

        $generator = $this->urlGenerator();
        $absolute = (bool) $this->arguments['absolute'];
        $map = [];
        foreach ((array) $this->arguments['routes'] as $key => $routeName) {
            $map[(string) $key] = $generator->generate($request, (string) $routeName, [], $absolute);
        }

        // JSON_HEX_TAG escapes <, > so the output stays safe to embed directly in an inline <script>.
        return json_encode($map, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG);
    }
}
