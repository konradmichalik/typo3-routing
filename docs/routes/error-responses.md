# Error responses from controllers

A controller answers an expected failure with the same [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem response the dispatcher uses for its own `404` or `400`, and a `JsonResponse` route keeps answering JSON when an unexpected exception escapes. The dispatcher's own `404` for unmatched paths is shaped through an event instead, see [Shaping the 404](../operating/configuration.md#shaping-the-404).

- [Throwing `HttpProblemException`](#throwing-httpproblemexception)
- [Automatic JSON errors for `JsonResponse` routes](#automatic-json-errors-for-jsonresponse-routes)

## Throwing `HttpProblemException`

Throw `HttpProblemException` to answer with the same [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem-details format the dispatcher uses for its own errors (`404`, `405`, `400`, …):

```php
use KonradMichalik\Typo3Routing\Http\HttpProblemException;

#[Route(path: '/api/orders/{id}/cancel', methods: ['POST'], name: 'order_cancel')]
public function cancel(Order $order): ResponseInterface
{
    if ($order->isShipped()) {
        throw new HttpProblemException(409, 'Order has already been shipped');
    }
    // …
}
```

The dispatcher maps it to `application/problem+json` — status `409`, title `Conflict`, and the message as `detail` (omitted when it only repeats the title). Only 4xx/5xx status codes are accepted; anything else raises a runtime `LogicException` when the exception is constructed.

Every **other** exception stays untouched and reaches TYPO3's regular error handling (and its logging) as before — `HttpProblemException` is for *expected* error outcomes, not a replacement for exception handling. The one exception to that: a controller method declaring a bare `JsonResponse` return type opts in to automatic JSON errors, described next.

## Automatic JSON errors for `JsonResponse` routes

A controller method whose declared return type is a bare, non-nullable `\TYPO3\CMS\Core\Http\JsonResponse` — no explicit opt-in needed — gets every otherwise-uncaught exception converted to a generic `500` `application/problem+json` response instead of reaching TYPO3's regular (HTML) error handling:

```php
use TYPO3\CMS\Core\Http\JsonResponse;

#[Route(path: '/api/orders/{id}', name: 'order_show')]
public function show(Order $order): JsonResponse
{
    // Any exception thrown here — one nobody anticipated — still answers as JSON.
    return new JsonResponse($order->toArray());
}
```

Since the converted exception never reaches TYPO3's exception handling, it is logged at `error` level through the TYPO3 logging framework (logger `KonradMichalik\Typo3Routing\Routing\ControllerInvoker`, exception under the `exception` context key) before the response is built.

Outside the `Development` application context, the response body never includes the exception's own message or a stack trace — only the fixed `title: 'Internal Server Error'` — since an unanticipated exception's detail is unvetted and possibly sensitive. In `Development`, the response includes the exception's message as `detail`, plus `exception`, `code`, `file`, `line` and `trace` as RFC 9457 extension members:

```json
{
    "type": "about:blank",
    "title": "Internal Server Error",
    "status": 500,
    "detail": "Call to a member function getId() on null",
    "exception": "Error",
    "code": 0,
    "file": "/var/www/html/src/Controller/ExampleController.php",
    "line": 42,
    "trace": [
        {
            "file": "/var/www/html/src/Service/ExampleService.php",
            "line": 123,
            "function": "doSomething",
            "class": "Vendor\\Extension\\Service\\ExampleService",
            "type": "->"
        }
    ]
}
```

Trace frames never include their `args`: argument values can carry secrets (passwords, tokens) that `Development` doesn't excuse exposing in an HTTP response. `HttpProblemException`, `ArgumentResolutionException` (unresolvable argument) and a missing entity are still mapped to their own specific status exactly as before, regardless of application context; this only adds a catch-all for everything else.

A nullable (`?JsonResponse`) or union return type does **not** opt in: the method can legitimately answer with something other than JSON, so guessing "JSON errors" for it would be wrong as often as it is right. A method with no declared return type is likewise unaffected.
