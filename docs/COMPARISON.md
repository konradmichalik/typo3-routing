# How It Compares

There are several ways to answer a frontend HTTP request in TYPO3. This extension targets attribute-declared API endpoints — the gap between "too much" (a full page/plugin) and "too manual" (hand-wired middleware):

| Approach | Where it fits | Trade-off this extension avoids |
|----------|---------------|----------------------------------|
| **`AjaxRoutes.php`** | Backend (BE) AJAX only | No frontend equivalent exists — this is the FE counterpart |
| **Custom PSR-15 middleware** | Any frontend request | You hand-wire matching, method/format handling, and duplicate the path in PHP + JS |
| **`eID` scripts** | Lightweight FE endpoints | Procedural entry points, no DI/typed arguments, manual routing and input handling |
| **Extbase plugin / `typeNum`** | Content-bound output | Heavyweight for a JSON endpoint; tied to a page and the rendering chain |
| **`typo3_routing`** | Attribute-declared FE endpoints | `#[Route]` on a service method — DI, typed arguments, URL generation, opt-in cache & rate limit |
