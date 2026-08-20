# Performance

Dispatching a matched route costs about **0.63 ms** more than a hand-written middleware doing the same thing, fixed per request rather than scaling with the payload. A request that cannot be a route, which is every ordinary page, costs about **0.19 ms**, almost all of it the DI container building the middleware rather than any routing work.

On the machine these figures come from, a warm request to a minimal JSON endpoint has a time-to-first-byte of roughly 20 ms, so the routing layer is about 3% of it, and proportionally less for any endpoint that touches the database.

## What this means for your case

| If you… | Expect |
|---------|--------|
| serve pages and never call an API route | ~0.19 ms per request, almost entirely container construction |
| call a matched route | ~0.63 ms of dispatch, fixed, independent of payload |
| pass path, query or body parameters | nothing on top of that |
| resolve an Extbase entity from the path | ~0.8 ms, the extra being the database lookup rather than the routing |
| use an opt-in feature (`#[Cache]`, `#[RateLimit]`, `#[DeprecatedRoute]`, `canonical`, `legacyPaths`) | under 0.2 ms where it acts, nothing where it does not |

Two things are worth planning around. **The overhead is a fixed cost per matched request, not a cost per argument:** a route with no arguments, one with a path placeholder and one with a query parameter all measure the same. And **a feature you have not opted into costs nothing**, because its classes are never even loaded.

## What an unrelated page request costs

The path gate runs on every frontend request, and the decision itself is genuinely almost free, well under a microsecond. What is not free is being in a position to ask: the middleware has to exist before its gate can reject anything, and constructing it is the ~0.19 ms above, roughly 0.7% of a 28 ms page render.

Everything the dispatcher needs only after the gate accepts a request is reached through a service locator rather than its constructor, so a page request never builds it. That is what `Classes/Middleware/DispatcherServices.php` is for, and it took this figure down from ~0.57 ms.

> [!TIP]
> The remainder is dominated by class loading rather than logic, so `opcache.preload` attacks it directly and needs no code change.

## Reproducing

```bash
ddev install 13                 # build the instance once; 14 works identically
ddev benchmark 13 60            # 60 requests per endpoint
```

The benchmark pairs every attribute route with a byte-for-byte identical plain PSR-15 middleware (the `routing_benchmark` fixture) registered at the same stack position, so the difference within a pair is the dispatch machinery and nothing else. Compare the absolute delta within a pair, and never compare figures measured in different sessions.

The same fixture ships `matching-microbench.php`, which measures the gate and the matcher on their own. They operate two to three orders of magnitude below the HTTP noise band, so only a microbenchmark can see them at all.

## Caveats

**Route count matters.** Matching cost scales with the size of the route collection. These figures come from an instance with 50 registered routes, 12 of them with placeholders. An installation with several hundred routes will measure differently.

**One machine.** Apple Silicon, OrbStack, PHP 8.2 in Docker. The figures are meaningful as relative statements and should be reproduced on your own hardware before being quoted as absolutes. What travels between machines is the shape of the result: a constant overhead per matched request, nothing measurable for unused features, and a sub-microsecond gate on unrelated traffic.
