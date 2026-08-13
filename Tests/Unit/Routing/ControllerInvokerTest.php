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
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\EntityController;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

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
    public function acceptsAMissingInputWhoseArgumentHasADefault(): void
    {
        $error = $this->invoker()->firstInputRequirementError(
            ['_route' => 'optional', '_requirements' => ['page' => '\d+']],
            $this->request(),
        );

        self::assertNull($error);
    }

    #[Test]
    public function stillRejectsAViolatingValueWhenTheArgumentHasADefault(): void
    {
        $error = $this->invoker()->firstInputRequirementError(
            ['_route' => 'optional', '_requirements' => ['page' => '\d+']],
            $this->request(['page' => 'abc']),
        );

        self::assertSame('Invalid value for parameter: page', $error);
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
        ];

        $locator = new ServiceLocator([
            'ctrl' => static fn (): ExampleController => new ExampleController(),
            'entityCtrl' => static fn (): EntityController => new EntityController(),
        ]);

        return new RouteRegistry([], $locator, arguments: $arguments);
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
}
