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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Routing;

use KonradMichalik\RoutingTest\Controller\ExampleController;
use KonradMichalik\Ttt\Assertion\JsonAssertions;
use KonradMichalik\Ttt\Attribute\InApplicationContext;
use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Typo3Routing\Routing\{ControllerArgumentResolver, ControllerInvoker, RouteRegistry};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{EntityController, ThrowingController};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Http\{ImmediateResponseException, ServerRequest, Stream};
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

use function json_decode;

/**
 * ControllerInvokerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ControllerInvoker::class)]
final class ControllerInvokerTest extends TestCase
{
    use JsonAssertions;

    #[Test]
    public function callsTheControllerNamedInTheMatch(): void
    {
        $response = $this->invoker()->invoke($this->match('count'), $this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function resolvesAPathPlaceholderFromTheMatch(): void
    {
        $response = $this->invoker()->invoke($this->match('item') + ['id' => '42'], $this->request());

        self::assertJsonStringEqualsJsonString('{"id":42}', (string) $response->getBody());
    }

    #[Test]
    public function exposesNonInternalMatchEntriesAsRequestAttributes(): void
    {
        $response = $this->invoker()->invoke($this->match('submit') + ['id' => '42'], $this->request());

        // ExampleController::submit() only reports the method — that the invocation succeeds at all
        // proves the internal "_" keys were not passed on as arguments.
        self::assertJsonStringEqualsJsonString('{"submitted":true,"method":"GET"}', (string) $response->getBody());
    }

    #[Test]
    public function mapsAnUnresolvableArgumentToBadRequest(): void
    {
        $response = $this->invoker()->invoke($this->match('item'), $this->request());

        self::assertSame(400, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Missing required parameter: id');
    }

    #[Test]
    public function mapsAMissingEntityToNotFound(): void
    {
        $response = $this->invoker()->invoke($this->match('entity') + ['item' => '5'], $this->request());

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function mapsAControllerProblemToItsOwnStatus(): void
    {
        $response = $this->invoker()->invoke($this->match('problem'), $this->request());

        self::assertSame(409, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Item already processed');
    }

    #[Test]
    public function convertsAnUncaughtExceptionToAGenericJsonErrorOnAFlaggedRoute(): void
    {
        $response = $this->invoker()->invoke($this->match('jsonError'), $this->request());

        self::assertSame(500, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'title', 'Internal Server Error');
        // Never the exception's own message: that detail is unvetted and possibly sensitive.
        self::assertJsonMissingPath((string) $response->getBody(), 'detail');
    }

    #[Test]
    #[InApplicationContext('Development')]
    public function includesExceptionDetailsInDevelopmentContext(): void
    {
        $response = $this->invoker()->invoke($this->match('jsonError'), $this->request());
        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertJsonPath($body, 'detail', 'sensitive internal detail nobody should see in a response');
        self::assertJsonPath($body, 'exception', RuntimeException::class);
        self::assertJsonPath($body, 'code', 6977582795);
        self::assertJsonHasPaths($body, ['file', 'line', 'trace']);

        // Frame arguments can carry secrets (passwords, tokens) even in Development.
        $trace = json_decode($body, true, 512, \JSON_THROW_ON_ERROR)['trace'];
        self::assertNotEmpty($trace);
        foreach ($trace as $frame) {
            self::assertArrayNotHasKey('args', $frame);
        }
    }

    #[Test]
    public function rethrowsAnUncaughtExceptionOnAnUnflaggedRoute(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sensitive internal detail nobody should see in a response');

        $this->invoker()->invoke($this->match('plainError'), $this->request());
    }

    #[Test]
    public function neverConvertsAnImmediateResponseExceptionEvenOnAFlaggedRoute(): void
    {
        // TYPO3 core's own control-flow signal, not an error: must reach AbstractApplication
        // unconverted regardless of the route's JSON-error flag.
        $this->expectException(ImmediateResponseException::class);

        $this->invoker()->invoke($this->match('shortCircuit'), $this->request());
    }

    #[Test]
    public function reportsAMissingRequiredInput(): void
    {
        $error = $this->invoker()->firstInputRequirementError(
            ['_requirements' => ['q' => '\d+']],
            $this->request(),
        );

        self::assertSame('Missing required parameter: q', $error);
    }

    #[Test]
    public function reportsAnInputViolatingItsPattern(): void
    {
        $error = $this->invoker()->firstInputRequirementError(
            ['_requirements' => ['q' => '\d+']],
            $this->request(['q' => 'abc']),
        );

        self::assertSame('Invalid value for parameter: q', $error);
    }

    #[Test]
    public function acceptsAnInputSatisfyingItsPattern(): void
    {
        $error = $this->invoker()->firstInputRequirementError(
            ['_requirements' => ['q' => '\d+']],
            $this->request(['q' => '5']),
        );

        self::assertNull($error);
    }

    #[Test]
    public function skipsARequirementAlreadyEnforcedOnThePath(): void
    {
        $error = $this->invoker()->firstInputRequirementError(
            ['_requirements' => ['id' => '\d+'], 'id' => '42'],
            $this->request(),
        );

        self::assertNull($error);
    }

    #[Test]
    public function acceptsARequirementWithoutAPattern(): void
    {
        $error = $this->invoker()->firstInputRequirementError(
            ['_requirements' => ['q' => '']],
            $this->request(['q' => 'anything']),
        );

        self::assertNull($error);
    }

    #[Test]
    public function acceptsAMissingInputMadeOptionalByItsParamAttribute(): void
    {
        $error = $this->invoker()->firstInputRequirementError(
            ['_route' => 'optional', '_requirements' => ['page' => '\d+']],
            $this->request(),
        );

        self::assertNull($error);
    }

    #[Test]
    public function stillRejectsAViolatingValueWhenTheInputIsOptional(): void
    {
        $error = $this->invoker()->firstInputRequirementError(
            ['_route' => 'optional', '_requirements' => ['page' => '\d+']],
            $this->request(['page' => 'abc']),
        );

        self::assertSame('Invalid value for parameter: page', $error);
    }

    #[Test]
    public function keepsARouteLevelRequirementMandatoryDespiteAPhpDefault(): void
    {
        // 'legacy' mirrors #[Route(requirements: ['page' => '\d+'])] on list(int $page = 1) with no
        // #[Param]: the requirement is the route's own, so its absence must still be a 400.
        $error = $this->invoker()->firstInputRequirementError(
            ['_route' => 'legacy', '_requirements' => ['page' => '\d+']],
            $this->request(),
        );

        self::assertSame('Missing required parameter: page', $error);
    }

    #[Test]
    public function returnsNullWhenTheRouteBindsNoBodyArguments(): void
    {
        $error = $this->invoker()->firstRequestBodyError(
            $this->match('count'),
            $this->bodyRequest('not json at all', 'text/plain'),
        );

        self::assertNull($error);
    }

    #[Test]
    public function returnsNullForAValidJsonBodyOnABodyBindingRoute(): void
    {
        $error = $this->invoker()->firstRequestBodyError(
            ['_route' => 'optional', '_requirements' => []],
            $this->bodyRequest('{"page":2}', 'application/json'),
        );

        self::assertNull($error);
    }

    #[Test]
    public function namesMalformedJsonAsTheCauseOnABodyBindingRoute(): void
    {
        $response = $this->invoker()->firstRequestBodyError(
            ['_route' => 'optional', '_requirements' => []],
            $this->bodyRequest('{"page":', 'application/json'),
        );

        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Malformed JSON request body');
    }

    #[Test]
    public function returnsUnsupportedMediaTypeForAnUnreadableContentTypeOnABodyBindingRoute(): void
    {
        $response = $this->invoker()->firstRequestBodyError(
            ['_route' => 'optional', '_requirements' => []],
            $this->bodyRequest('page=2', 'text/plain'),
        );

        self::assertNotNull($response);
        self::assertSame(415, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Accept-Post'));
    }

    #[Test]
    public function treatsARouteWithoutAnEnvAsVisibleEverywhere(): void
    {
        self::assertTrue($this->invoker()->isVisibleInCurrentContext(null));
        self::assertTrue($this->invoker()->isVisibleInCurrentContext(''));
    }

    #[Test]
    #[InApplicationContext('Development')]
    public function matchesTheCurrentApplicationContextCaseInsensitively(): void
    {
        self::assertTrue($this->invoker()->isVisibleInCurrentContext('development'));
        self::assertFalse($this->invoker()->isVisibleInCurrentContext('Production'));
    }

    #[Test]
    #[InApplicationContext('Development/Local')]
    public function comparesOnlyTheFirstSegmentOfTheApplicationContext(): void
    {
        self::assertTrue($this->invoker()->isVisibleInCurrentContext('Development'));
    }

    private function invoker(): ControllerInvoker
    {
        return new ControllerInvoker($this->registry(), new ControllerArgumentResolver($this->createMock(PersistenceManagerInterface::class)));
    }

    /**
     * @return array<string, mixed>
     */
    private function match(string $routeName): array
    {
        $controllers = [
            'count' => 'ctrl::count',
            'item' => 'ctrl::item',
            'submit' => 'ctrl::submit',
            'problem' => 'ctrl::problem',
            'entity' => 'entityCtrl::show',
            'jsonError' => 'throwingCtrl::boom',
            'plainError' => 'throwingCtrl::boom',
            'shortCircuit' => 'throwingCtrl::shortCircuit',
        ];

        return ['_route' => $routeName, '_controller' => $controllers[$routeName], '_requirements' => []];
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>> $arguments */
        $arguments = [
            'count' => [],
            'item' => [['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'submit' => [['name' => 'request', 'type' => null, 'source' => 'request', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'problem' => [],
            'entity' => [['name' => 'item', 'type' => Item::class, 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'optional' => [['name' => 'page', 'type' => 'int', 'source' => 'input', 'nullable' => false, 'hasDefault' => true, 'default' => 1]],
            'legacy' => [['name' => 'page', 'type' => 'int', 'source' => 'input', 'nullable' => false, 'hasDefault' => true, 'default' => 1]],
            'jsonError' => [],
            'plainError' => [],
            'shortCircuit' => [],
        ];

        $locator = new ServiceLocator([
            'ctrl' => static fn (): ExampleController => new ExampleController(),
            'entityCtrl' => static fn (): EntityController => new EntityController(),
            'throwingCtrl' => static fn (): ThrowingController => new ThrowingController(),
        ]);

        // Only 'optional' has its requirement contributed by #[Param] on a defaulted parameter.
        // 'jsonError' and 'shortCircuit' both opted into converting an uncaught exception to a
        // generic JSON error — 'shortCircuit' proves an ImmediateResponseException is excluded
        // from that conversion even so.
        return new RouteRegistry([], $locator, arguments: $arguments, optionalInputs: ['optional' => ['page']], jsonErrorRoutes: ['jsonError' => true, 'shortCircuit' => true]);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query = []): ServerRequest
    {
        return (new RequestBuilder('GET', 'https://example.com/api/count'))
            ->withQueryParams($query)
            ->build();
    }

    private function bodyRequest(string $body, string $contentType): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return (new RequestBuilder('POST', 'https://example.com/api/count'))
            ->build()
            ->withBody($stream)
            ->withHeader('Content-Type', $contentType);
    }
}
