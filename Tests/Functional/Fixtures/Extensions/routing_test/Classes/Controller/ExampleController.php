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

namespace KonradMichalik\RoutingTest\Controller;

use KonradMichalik\RoutingTest\Enum\Status;
use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Cache, Param, RateLimit, RequireRequestToken, Route};
use KonradMichalik\Typo3Routing\Authentication\BearerTokenAuthenticator;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * ExampleController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExampleController implements RouteControllerInterface
{
    #[Route(path: '/api/example/count', name: 'example_count')]
    public function count(): JsonResponse
    {
        return new JsonResponse(['count' => 3]);
    }

    #[Route(path: '/api/example/secure', name: 'example_secure')]
    #[Authenticate(BearerTokenAuthenticator::class, options: ['envName' => 'ROUTING_TEST_TOKEN'])]
    public function secure(): JsonResponse
    {
        // Reachable only with a valid "Authorization: Bearer <token>" matching the env variable.
        return new JsonResponse(['secure' => true]);
    }

    #[Route(path: '/api/example/token', methods: ['POST'], name: 'example_token')]
    #[RequireRequestToken(scope: 'routing/example-token')]
    public function token(): JsonResponse
    {
        // Reachable only with a valid request token of the matching scope.
        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/api/example/limited', name: 'example_limited')]
    #[RateLimit(limit: 1, interval: '1 minute', policy: 'sliding_window')]
    public function limited(): JsonResponse
    {
        return new JsonResponse(['limited' => true]);
    }

    #[Route(path: '/api/example/submit', methods: ['POST'], name: 'example_submit')]
    public function submit(ServerRequestInterface $request): JsonResponse
    {
        // The request itself stays injectable for handlers that need headers, body, attributes, …
        return new JsonResponse(['submitted' => true, 'method' => $request->getMethod()]);
    }

    #[Route(path: '/api/example/dev', name: 'example_dev', env: 'Development')]
    public function dev(): JsonResponse
    {
        return new JsonResponse(['dev' => true]);
    }

    #[Route(path: '/api/example/item/{id}', name: 'example_item', requirements: ['id' => '\d+'])]
    public function item(int $id): JsonResponse
    {
        // The path placeholder arrives type-cast to int (encoded as a JSON number).
        return new JsonResponse(['id' => $id]);
    }

    #[Route(path: '/api/example/search', name: 'example_search', requirements: ['q' => '\d+'])]
    public function search(int $q): JsonResponse
    {
        // A query parameter, type-cast to int.
        return new JsonResponse(['q' => $q]);
    }

    #[Route(path: '/api/example/range', name: 'example_range')]
    public function range(int $from, int $to = 10, ?string $label = null): JsonResponse
    {
        // Mixed sources with defaults: required + optional query parameters.
        return new JsonResponse(['from' => $from, 'to' => $to, 'label' => $label]);
    }

    #[Route(path: '/api/example/status/{status}', name: 'example_status')]
    public function status(Status $status): JsonResponse
    {
        // A backed enum resolved from a path placeholder.
        return new JsonResponse(['status' => $status->value]);
    }

    #[Route(path: '/api/example/tags', name: 'example_tags')]
    public function tags(string ...$tags): JsonResponse
    {
        // Variadic: ?tags[]=a&tags[]=b expands to ('a', 'b').
        return new JsonResponse(['tags' => $tags]);
    }

    #[Route(path: '/api/example/aliased', name: 'example_aliased')]
    public function aliased(#[Param(name: 'q')] string $term): JsonResponse
    {
        // #[Param] reads the query key "q" into a differently named parameter.
        return new JsonResponse(['term' => $term]);
    }

    #[Route(path: '/api/example/cached', name: 'example_cached')]
    #[Cache(lifetime: 3600, tags: ['pages'])]
    public function cached(): JsonResponse
    {
        // A fresh token each call — identical across requests proves a cache hit.
        return new JsonResponse(['token' => bin2hex(random_bytes(8))]);
    }
}
