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

use KonradMichalik\Typo3Routing\Routing\CaseInsensitiveRouteCompiler;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\{CompiledRoute, Route as SymfonyRoute};

/**
 * CaseInsensitiveRouteCompilerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CaseInsensitiveRouteCompiler::class)]
final class CaseInsensitiveRouteCompilerTest extends TestCase
{
    #[Test]
    public function theCompiledRegexMatchesTheDeclaredPathInAnyCase(): void
    {
        $regex = $this->compile('/api/example')->getRegex();

        self::assertSame(1, preg_match($regex, '/api/example'));
        self::assertSame(1, preg_match($regex, '/API/Example'));
        self::assertSame(1, preg_match($regex, '/aPi/eXaMpLe'));
    }

    #[Test]
    public function aPathThatDiffersBeyondItsCaseStillDoesNotMatch(): void
    {
        $regex = $this->compile('/api/example')->getRegex();

        self::assertSame(0, preg_match($regex, '/api/other'));
    }

    /**
     * UrlMatcher checks the static prefix with a case-sensitive str_starts_with before it ever runs
     * the regex. Leaving the prefix in place would short-circuit every differently-cased request.
     */
    #[Test]
    public function theStaticPrefixIsEmptiedSoTheMatcherDoesNotShortCircuit(): void
    {
        self::assertSame('', $this->compile('/api/example')->getStaticPrefix());
    }

    #[Test]
    public function placeholderValuesKeepTheirOriginalCase(): void
    {
        preg_match($this->compile('/api/item/{slug}')->getRegex(), '/API/Item/MySlug', $matches);

        self::assertSame('MySlug', $matches['slug']);
    }

    #[Test]
    public function tokensAndVariablesAreTakenOverFromTheStandardCompilation(): void
    {
        $route = new SymfonyRoute('/api/item/{slug}');
        $compiled = $this->compile('/api/item/{slug}');

        self::assertSame($route->compile()->getTokens(), $compiled->getTokens());
        self::assertSame(['slug'], $compiled->getPathVariables());
        self::assertSame(['slug'], $compiled->getVariables());
    }

    #[Test]
    public function aHostRestrictionIsCompiledCaseInsensitivelyAsWell(): void
    {
        $route = new SymfonyRoute('/api/example', host: 'api.example.com');
        $route->setOption('compiler_class', CaseInsensitiveRouteCompiler::class);

        $hostRegex = $route->compile()->getHostRegex();

        self::assertIsString($hostRegex);
        self::assertSame(1, preg_match($hostRegex, 'API.Example.com'));
    }

    #[Test]
    public function aRouteWithoutAHostHasNoHostRegex(): void
    {
        self::assertNull($this->compile('/api/example')->getHostRegex());
    }

    private function compile(string $path): CompiledRoute
    {
        $route = new SymfonyRoute($path);
        $route->setOption('compiler_class', CaseInsensitiveRouteCompiler::class);

        return $route->compile();
    }
}
