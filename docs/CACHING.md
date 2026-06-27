# Caching

Add the optional `#[Cache]` attribute next to a `#[Route]` to cache the response — the controller stays unaware of caching:

```php
use KonradMichalik\Typo3Routing\Attribute\Cache;
use KonradMichalik\Typo3Routing\Attribute\Route;

// e.g. GET /api/news?page=2&search=foo
//   → cached per "page", but the volatile "search" query parameter is excluded from the cache key
#[Route(path: '/api/news', name: 'news_list')]
#[Cache(lifetime: 3600, tags: ['tx_news_domain_model_news'], ignoreParams: ['search'])]
public function list(ServerRequestInterface $request): ResponseInterface
{
    $page = (int) ($request->getQueryParams()['page'] ?? 1);
    // …
}
```

| Parameter      | Type           | Default | Description                                                                       |
|----------------|----------------|---------|-----------------------------------------------------------------------------------|
| `lifetime`     | `int`          | `86400` | Time to live in seconds (fallback when no tag is invalidated).                    |
| `tags`         | `list<string>` | `[]`    | Cache tags. A tag matching a table name is flushed automatically when a record of that table changes (via DataHandler). |
| `ignoreParams` | `list<string>` | `[]`    | Query parameters excluded from the cache key (e.g. an individual `search` term).  |

- Only **successful (`200`) GET responses** are cached. The cache key is built from route name, path, query string (minus `ignoreParams`) and language, so query/language variants are cached separately.
- Invalidation rides on the TYPO3 caching framework: changing a record of a tagged table flushes the matching entries immediately; `lifetime` is the fallback. The response is stored via the TYPO3 cache backend (no extra cache layer of its own).

> [!CAUTION]
> Only cache responses that are the **same for everyone**. With the default middleware placement the dispatcher runs before authentication, so responses are not user-specific — but if you move it after `typo3/cms-frontend/authentication` (see [Configuration](CONFIGURATION.md#middleware-placement)) and cache a personalized response, you risk serving one user's data to another. `Set-Cookie` headers are never cached.
