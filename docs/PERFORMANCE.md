# Performance

**Short answer:** dispatching a matched route costs about **0.6 ms** more than a hand-written middleware doing the same thing, and that cost is fixed per request. Requests that cannot be a route (every ordinary page) are filtered out in **under a microsecond**. Features you do not use cost nothing, which was measured per feature rather than assumed.

On the machine these figures come from, a warm end-to-end request to a minimal JSON endpoint has a time-to-first-byte of roughly **20 ms**, so the routing layer is about **3%** of it, and proportionally less for any endpoint that touches the database.

## What this means for your case

| If you… | Expect |
|---------|--------|
| serve pages and never call an API route | ~0.7 µs per request, once, in the path gate |
| call a matched route | ~0.6 ms of dispatch, fixed, independent of payload |
| pass path or query parameters | nothing on top of that, the cost is per request, not per argument |
| resolve an Extbase entity from the path | ~0.7 ms, the extra being the database lookup, not the routing |
| use `caseInsensitive` and request the declared casing | nothing extra, measurably identical to a plain route |
| use `caseInsensitive` and request a different casing | ~+0.1 ms, and only on requests that would otherwise be a 404 |
| leave a feature unused (`#[Cache]`, `#[RateLimit]`, `#[Authenticate]`, `#[Cors]`) | nothing |

The most useful fact for capacity planning is in the second and third rows: **for this route set, the overhead is a fixed cost per matched request, not a cost per argument.** Matching cost still scales with the route collection, see [Caveats](#caveats). A route with no arguments, one with a path placeholder and one with a query parameter all measure the same.

## The cost of a matched request

TYPO3 v13, PHP 8.2, 60 requests per endpoint, four runs in a balanced A-B-B-A order. Each value is the median of the attribute route minus the median of a byte-for-byte identical hand-written middleware measured in the same run.

| Scenario | Overhead vs. hand-rolled middleware | Range across runs |
|----------|-------------------------------------|-------------------|
| noop (no arguments) | **+0.58 ms** | +0.56 … +0.60 |
| path parameter `{id}` | **+0.58 ms** | +0.57 … +0.63 |
| query parameter `?q` | **+0.58 ms** | +0.57 … +0.60 |
| entity resolution `{item}` | **+0.72 ms** | +0.69 … +0.78 |
| `caseInsensitive`, exact casing | **+0.58 ms** | +0.57 … +0.58 |
| `caseInsensitive`, different casing | **+0.67 ms** | +0.66 … +0.68 |

> [!WARNING]
> **Ignore the percentages that `ddev benchmark` prints** (`+527%` and similar). They are ratios against a deliberately minimal baseline: the comparison middleware returns a `JsonResponse` after a single `if`, so its median is around 0.11 ms and any real dispatcher looks catastrophic next to it. The absolute delta is the number that means something.

`entity resolution` is database-bound and by far the noisiest scenario, with single outliers to 10 ms. Do not read small differences there.

## What costs nothing

### Traffic that is not an API request

The path gate runs on **every** frontend request, including every ordinary page, and it is the only cost the extension imposes on traffic that has nothing to do with routing. It measures **0.7 to 0.8 µs** on a page path matching no route prefix, roughly 0.003% of a 28 ms page render.

This is the number to look at if you run a large TYPO3 site with a handful of API routes: the extension is installed everywhere but charges almost nothing outside its own paths. An API request that matches on the first prefix returns after a single `str_starts_with` at 0.05 µs, which is why the gate is a filter and not a lookup.

### Features you have not opted into

Every opt-in feature is gated behind a registry lookup that returns `null` when the route did not declare it. That makes "opt-in" mean *free*, not merely *cheap*, and the microbenchmark below is what makes that checkable instead of promotional. The clearest case is `caseInsensitive`: a route requested in its declared casing measures identically to a plain static route, both at the request level (+0.58 ms) and at the mechanism level (0.33 µs), because the tolerance is a fallback that an exact hit never reaches.

## Case-insensitive paths

Measured from three directions, because "what does the opt-in cost" and "what does the tolerance cost when it fires" are different questions.

**1. Nothing when it is not needed.** See above. The fallback is consulted only after the primary matcher has already thrown.

**2. No regression against 1.0.0.** The four scenarios that exist on both sides, balanced A-B-B-A against `main` at v1.0.0:

| Scenario | branch | main |
|----------|--------|------|
| noop | +0.59 / +0.58 ms | +0.56 / +0.60 ms |
| path parameter | +0.58 / +0.58 ms | +0.57 / +0.63 ms |
| query parameter | +0.58 / +0.58 ms | +0.57 / +0.60 ms |
| entity resolution | +0.69 / +0.72 ms | +0.72 / +0.78 ms |

The ranges overlap completely.

**3. About +0.1 ms when the tolerance fires.** A differently-cased request measures +0.66 to +0.68 ms against +0.58 ms for the exact path. That covers the whole fallback chain: a failed compiled match, a failed trailing-slash retry, a freshly built `UrlMatcher` over the opted-in collection, then the requirement re-check.

> [!NOTE]
> The fallback uses the plain `UrlMatcher`, not the compiled matcher baked at container build time. That is deliberate and explained in [How It Works](HOW-IT-WORKS.md): `CompiledUrlMatcherDumper` resolves placeholder-free routes through an exact-match table that no regex modifier can reach. The +0.1 ms is the price of that decision, and it is only ever paid by a request that would otherwise have been a 404.

## Mechanism-level results

Two things are measured in this project, because one measurement cannot answer both questions:

| Question | Tool | Resolution |
|----------|------|------------|
| What does a request cost compared to a hand-rolled middleware? | `ddev benchmark` | ~0.03 ms noise band |
| What does one mechanism (the path gate, the matcher fallback) cost? | `matching-microbench.php` | ~0.05 µs |

The mechanisms operate two to three orders of magnitude below the HTTP noise band, so an HTTP benchmark can only ever say "no visible difference" about them. That is why both exist.

30 000 iterations per case, three runs, 41 routes (21 static, 20 with placeholders, 1 opted into `caseInsensitive`). Single process, warm opcache, no HTTP and no TYPO3 bootstrap.

| Case | per operation |
|------|---------------|
| Path gate, page request, no `caseInsensitive` route | 0.72 … 0.76 µs |
| Path gate, page request, one `caseInsensitive` route | 0.78 … 0.82 µs |
| Path gate, API request (matches on the first prefix) | 0.05 µs |
| Match static route, exact path | 0.33 … 0.36 µs |
| Match placeholder route, exact path | 0.57 … 0.61 µs |
| Match `caseInsensitive` route, exact path | 0.33 … 0.37 µs |
| Match `caseInsensitive` route, different casing (per request) | 6.11 … 6.33 µs |
| 404 under a claimed prefix, no `caseInsensitive` route | 1.22 … 1.26 µs |
| 404 under a claimed prefix, one `caseInsensitive` route | 5.87 … 5.95 µs |

One route opting into `caseInsensitive` adds **+0.02 to +0.09 µs** to the gate, because it then lower-cases the path and walks a second prefix list once the first has missed. Measurable in a tight loop, invisible in a request.

The same opt-in also takes every **404** under a claimed prefix from 1.2 µs to 5.9 µs of matching work, because `getCaseInsensitiveMatcher()` stops returning `null` and a third attempt is made. Under 5 µs in absolute terms, but worth knowing if an installation serves a high volume of 404s inside an [exclusive prefix](CONFIGURATION.md).

### Why the two levels disagree about the fallback

The mechanism-level figure for a differently-cased path (6.2 µs) and the request-level figure (~+0.1 ms, so ~100 µs) do not agree, and the gap is informative rather than a measurement error. The microbenchmark charges only the matching work. The real request additionally pays for a registry constructed for that request, the collection built from the full baked route array, two exception constructions with `sprintf`-built messages, and cold branch prediction.

Plan with the request-level number. Use the mechanism-level number to tell whether a code change moved the mechanism.

## Reproducing

```bash
ddev install 13                 # build the instance (once)
ddev benchmark 13 60            # 60 requests per endpoint, request level
```

```bash
# mechanism level, no HTTP and no TYPO3 bootstrap
php Tests/Functional/Fixtures/Extensions/routing_benchmark/Resources/Private/Scripts/matching-microbench.php 30000
```

The request-level benchmark pairs every attribute route with a **byte-for-byte identical plain PSR-15 middleware** (the `routing_benchmark` fixture) registered at the same stack position. The difference within a pair is the dispatch machinery and nothing else.

> [!IMPORTANT]
> `timing.total_ms` from `typo3-request-profiler` is **not** a full request. The profiler middleware is deliberately re-ordered to wrap both benchmark endpoints, so the span starts there and covers dispatch inward. It excludes TYPO3 bootstrap, which both variants share anyway. Read its absolute figures as "cost of dispatch", not "cost of the request". The ~20 ms end-to-end anchor quoted at the top was measured separately, with `curl`.

## Methodology

Three traps make a naive comparison on a local Docker setup worthless. All three are avoided by the runs above and should be avoided by any future measurement.

1. **Compare the overhead, not the absolute median.** Run-to-run spread of the absolute routing median is larger than most refactor effects. The plain endpoint measured in the *same run* is the control that cancels machine noise, which is why every table here reports a delta.
2. **Balance the run order.** Running branch-then-main in every round systematically favours whichever side goes second, because caches are warmer after the `cache:flush` that opens each run. An A-B-B-A design removes it. An earlier unbalanced comparison in this project produced a +0.05 to +0.08 ms "regression" that did not exist.
3. **`git checkout main` is not `origin/main`.** After a fetch, local `main` can be stale, and the comparison then silently runs against the previous release. The runs above used a detached `origin/main`, verified at `1b10d60`.

A fourth, specific to this repository: earlier reference figures recorded in July 2026 (0.23 ms rather than 0.58 ms) do **not** reproduce on the current machine state. They are not a regression baseline. Only ever compare two sides measured in the same session.

## Caveats

**Route count matters.** Matching cost scales with the size of the route collection. The request-level figures come from an instance with **26 registered routes**, 6 of them with placeholders. An installation with several hundred routes will measure differently. The microbenchmark deliberately uses a larger set (41) than the instance, so its numbers are not flattered by a tiny collection.

**One machine.** Everything here comes from a single setup: Apple Silicon, OrbStack, PHP 8.2 in Docker. The figures are meaningful as relative statements and should be reproduced on your own hardware before being quoted as absolutes. What travels between machines is the shape of the result: a constant overhead per matched request, nothing measurable for unused features, and a sub-microsecond gate on unrelated traffic.
