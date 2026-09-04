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

namespace KonradMichalik\Typo3Routing\Command;

use KonradMichalik\Typo3Routing\Routing\{RequirementMismatchException, RouteMatcher};
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;

use function array_map;
use function implode;
use function in_array;
use function is_string;
use function ltrim;
use function sprintf;
use function str_starts_with;
use function strtoupper;

/**
 * RouteMatchCommand.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(name: 'routing:match', description: 'Simulate matching a request path against the registered attribute routes')]
final class RouteMatchCommand extends Command
{
    public function __construct(
        private readonly RouteMatcher $matcher,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'The request path to match (without the site base), e.g. /api/item/42');
        $this->addOption('method', null, InputOption::VALUE_REQUIRED, 'Request method (default: GET)');
        $this->addOption('scheme', null, InputOption::VALUE_REQUIRED, 'Request scheme (default: https)');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Request host (default: localhost)');
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Simulate this site identifier, to check a route\'s "sites" constraint');
        $this->addOption('language', null, InputOption::VALUE_REQUIRED, 'Simulate this language id, to check a route\'s "languages" constraint');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = '/'.ltrim((string) $input->getArgument('path'), '/');
        $context = $this->context($input);

        try {
            // The dispatcher's matcher, so the simulation reflects its trailing-slash tolerance too.
            $match = $this->matcher->match($path, $context);
        } catch (RequirementMismatchException $exception) {
            // Ordered before the plain miss it extends: a route was found, so "no route matches" would
            // send the reader looking for the wrong bug.
            $io->warning(sprintf('Path "%s" matches route "%s", but the value "%s" for parameter "%s" does not satisfy its requirement "%s".', $path, $exception->routeName, $exception->value, $exception->parameter, $exception->requirement));
            $io->note('The route opted into caseInsensitive, which covers the path\'s literal segments only. Placeholder values and their requirements stay case-sensitive.');

            return Command::FAILURE;
        } catch (ResourceNotFoundException) {
            $io->error(sprintf('No route matches "%s %s" (scheme %s, host %s).', $context->getMethod(), $path, $context->getScheme(), $context->getHost()));

            return Command::FAILURE;
        } catch (MethodNotAllowedException $exception) {
            $io->warning(sprintf('Path "%s" matches, but method "%s" is not allowed. Allowed: %s.', $path, $context->getMethod(), implode(', ', $exception->getAllowedMethods())));

            return Command::FAILURE;
        }

        $rejection = $this->siteRejection($match, $input) ?? $this->languageRejection($match, $input);
        if (null !== $rejection) {
            $io->warning($rejection);

            return Command::FAILURE;
        }

        $this->render($io, $match);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function siteRejection(array $match, InputInterface $input): ?string
    {
        /** @var list<string> $sites */
        $sites = $match['_sites'] ?? [];
        $site = $this->option($input, 'site');
        if ([] === $sites || null === $site || in_array($site, $sites, true)) {
            return null;
        }

        return sprintf('Path matches route "%s", but simulated site "%s" is not in its allowed sites: "%s".', (string) ($match['_route'] ?? ''), $site, implode('", "', $sites));
    }

    /**
     * @param array<string, mixed> $match
     */
    private function languageRejection(array $match, InputInterface $input): ?string
    {
        /** @var list<int> $languages */
        $languages = $match['_languages'] ?? [];
        $language = $this->option($input, 'language');
        if ([] === $languages || null === $language) {
            return null;
        }

        $languageId = (int) $language;
        if (in_array($languageId, $languages, true)) {
            return null;
        }

        return sprintf('Path matches route "%s", but simulated language %d is not in its allowed languages: "%s".', (string) ($match['_route'] ?? ''), $languageId, implode('", "', array_map(strval(...), $languages)));
    }

    private function context(InputInterface $input): RequestContext
    {
        $context = new RequestContext();
        $context->setMethod(strtoupper($this->option($input, 'method') ?? 'GET'));
        $context->setScheme($this->option($input, 'scheme') ?? 'https');
        $context->setHost($this->option($input, 'host') ?? 'localhost');

        return $context;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function render(SymfonyStyle $io, array $match): void
    {
        $routeName = (string) ($match['_route'] ?? '');
        $io->success(sprintf('Matched route "%s".', $routeName));

        $rows = [
            ['Route' => $routeName],
            ['Controller' => (string) ($match['_controller'] ?? '-')],
            ['Parameters' => $this->formatParameters($match)],
        ];

        $scheme = $match['_schemeRedirect'] ?? null;
        if (is_string($scheme)) {
            // The matcher deliberately matches across the scheme constraint so the dispatcher can
            // redirect rather than 404 — without this row the match would look like a plain hit. Site
            // and language are already ruled out by this point (see siteRejection()/languageRejection()
            // above), but env is only ever reported, never enforced here — see the Env row below.
            $rows[] = ['Scheme' => $scheme.' (the simulated scheme does not match; a visible route receives 308 here, but an Env row below still means the dispatcher may answer 404 instead)'];
        }

        $env = $match['_env'] ?? null;
        if (is_string($env) && '' !== $env) {
            // The matcher ignores env; the dispatcher hides the route (404) outside this context.
            $rows[] = ['Env' => $env.' (only reachable in this application context)'];
        }

        /** @var list<string> $sites */
        $sites = $match['_sites'] ?? [];
        if ([] !== $sites) {
            // The matcher ignores sites/languages too; the dispatcher hides the route (404) outside them.
            $rows[] = ['Sites' => implode(', ', $sites).' (only reachable from these sites)'];
        }

        /** @var list<int> $languages */
        $languages = $match['_languages'] ?? [];
        if ([] !== $languages) {
            $rows[] = ['Languages' => implode(', ', array_map(strval(...), $languages)).' (only reachable in these languages)'];
        }

        $io->definitionList(...$rows);
    }

    /**
     * Renders the resolved path placeholders (the non-internal, string-valued match entries).
     *
     * @param array<string, mixed> $match
     */
    private function formatParameters(array $match): string
    {
        $parts = [];
        foreach ($match as $key => $value) {
            if (!str_starts_with($key, '_') && is_string($value)) {
                $parts[] = $key.': '.$value;
            }
        }

        return [] === $parts ? '-' : implode(', ', $parts);
    }

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && '' !== $value ? $value : null;
    }
}
