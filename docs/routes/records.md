# List/detail endpoints for database records

This is a recipe, not a feature: the extension ships no record, query, or serialisation layer, and does not plan to add one (see [Decision: why this is documentation, not a feature](#decision-why-this-is-documentation-not-a-feature)). Everything below combines pieces documented elsewhere. What follows is how to combine them for the recurring case of exposing a database table as a list and a detail endpoint.

## The base-class pattern

[Sharing route definitions through a base class](route-groups.md#sharing-route-definitions-through-a-base-class) fits a resource controller well: the list and detail routes are declared once, and each concrete resource supplies only its own path/name prefix.

```php
use KonradMichalik\Typo3Routing\Attribute\{Param, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Requirement\Requirement;

abstract class ResourceController implements RouteControllerInterface
{
    #[Route(path: '', name: 'list')]
    final public function list(#[Param(requirement: '\d+')] int $page = 1, #[Param] ?string $q = null): ResponseInterface
    {
        return $this->respondWithList($this->query($page, $q));
    }

    #[Route(path: '/{uid}', name: 'detail', requirements: ['uid' => Requirement::DIGITS])]
    final public function detail(int $uid): ResponseInterface
    {
        return $this->respondWithDetail($this->findOne($uid));
    }

    /** @return list<string> */
    abstract protected function fields(): array;

    abstract protected function query(int $page, ?string $q): iterable;

    abstract protected function findOne(int $uid): ?object;

    abstract protected function respondWithList(iterable $records): ResponseInterface;

    abstract protected function respondWithDetail(?object $record): ResponseInterface;
}

// → GET /api/products and /api/products/{uid}
#[Route(path: '/api/products', name: 'products_')]
final class ProductController extends ResourceController { /* … */ }
```

The route methods are `final` on purpose: overriding one instead of the `abstract protected` hooks would silently drop it, exactly as described for the base-class pattern in general.

[`#[Param]`](arguments.md#overriding-the-source-with-param) resolves `page` and `q` with their `requirement` hoisted into the route, so a malformed `page` is a **400** before `query()` ever runs. The detail route's `#[uid]` requirement gives the same guarantee for the identifier, and typing `findOne()`'s parameter (or the method itself) against an Extbase `DomainObjectInterface` would additionally resolve the record for you — see [Entity resolution](arguments.md#entity-resolution) — though a hand-written `findOne()` is usually the better fit here, since it is the one place field exposure and `pid` scoping (below) need to happen anyway.

## What the consumer owns

None of these are provided, and none should be inferred from TCA:

| Concern | Guidance |
| --- | --- |
| Query construction | `QueryBuilder` with the frontend restriction container, or the v13.4+ `Record` API |
| Field exposure | An explicit field whitelist per resource, not TCA-derived, so a TCA change cannot silently alter the public response |
| Filtering | A declared set of filterable fields × allowed operators, never a free-form grammar mapped to SQL |
| Sorting | A whitelist of sortable fields |
| Pagination | A hard `maxLimit` cap; an unbounded `limit` is a denial-of-service surface |
| Response shape | Owned and versioned by the project, not by this extension |

## Pitfalls

- **`pid` scope on the detail endpoint.** Without it, `/products/{uid}` is an enumeration oracle over the entire table, regardless of which records the site is supposed to expose. Entity binding resolves any valid identifier without an ownership check — see the [warning in Entity resolution](arguments.md#entity-resolution).
- **Restrictions.** `deleted`, `hidden`, `starttime`/`endtime` and `fe_group` all need to be applied. The frontend restriction container is the correct default, not `QueryBuilder`'s own defaults.
- **Language overlay and site scope.** A record API that ignores language returns default-language rows to a translated request.
- **Cache tags.** A list response cached with [`#[Cache]`](../features/caching.md) never invalidates unless the controller adds table- and uid-level cache tags itself; the attribute's `tags` option does not derive them for you.
- **Workspaces**, if the project uses them.

## Decision: why this is documentation, not a feature

- **Dependency surface.** The extension currently depends on routing, DI and PSR interfaces, a stable base that keeps the PHP 8.2–8.5 × TYPO3 13.4/14.0/14.3 support matrix cheap. A record layer would add TCA, `QueryBuilder`, restrictions, language overlay and workspaces: the fastest-moving part of the platform. Every break there would land on consumers who only wanted routes.
- **Semver cost.** A record layer binds three contracts to semver at once: filter grammar, pagination envelope, serialisation shape, none of which are reliably right on the first attempt.
- **Positioning.** The value of [How it compares](../background/comparison.md) is that this extension is *smaller* than the alternatives it lists. A record API would blur that line rather than sharpen it.
- **Entity binding is not a precedent for this.** It is argument resolution: one identifier to one object, through Extbase's persistence, with no query semantics and no output format. A record API would own query construction and response shape, a categorically larger commitment.

**Revisit condition:** if independent consumer projects converge on the same filter and envelope shape, that convergence is what would justify a satellite package. Not before.
