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

use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function in_array;
use function is_string;
use function sprintf;

/**
 * RouteDebugCommand.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(name: 'routing:debug', description: 'List all registered attribute routes')]
final class RouteDebugCommand extends Command
{
    public function __construct(
        private readonly RouteRegistry $registry,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Show one route in detail (exact name) or filter by a name substring');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the routes as JSON (machine-readable)');
        $this->addOption('method', null, InputOption::VALUE_REQUIRED, 'Only routes accepting this HTTP method');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Only routes whose path contains this substring');
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Only routes bound to this application context');
        $this->addOption('unprotected', null, InputOption::VALUE_NONE, 'Only routes without an authenticator (audit: "show me every open endpoint")');
        $this->addOption('protected', null, InputOption::VALUE_NONE, 'Only routes guarded by an authenticator');
        $this->addOption('cached', null, InputOption::VALUE_NONE, 'Only routes with response caching');
        $this->addOption('rate-limited', null, InputOption::VALUE_NONE, 'Only routes with rate limiting');
        $this->addOption('csrf', null, InputOption::VALUE_NONE, 'Only routes requiring a CSRF request token');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $this->stringArgument($input, 'name');
        $json = true === $input->getOption('json');
        $rows = $this->collectRows();

        if (null !== $name && !$json && isset($rows[$name])) {
            $this->renderDetail($io, $rows[$name]);

            return Command::SUCCESS;
        }

        $filters = $this->activeFilters($input, $name);
        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->matches($row, $filters)));

        if ($json) {
            $output->writeln(json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ([] === $rows) {
            $io->warning([] === $filters ? 'No attribute routes registered.' : 'No matching attribute routes.');

            return Command::SUCCESS;
        }

        $io->title('Attribute Routes');
        if ([] !== $filters) {
            $io->comment('Filters: '.implode(', ', array_keys($filters)));
        }
        $io->table(
            ['Name', 'Path', 'Methods', 'Controller', 'Env', 'Requirements', 'Auth', 'CSRF', 'Description'],
            array_map(RouteTableFormatter::tableRow(...), $rows),
        );

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{name: string, path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes: list<string>, host: string|null, description: string|null, caseInsensitive: bool, tags: list<string>, canonical: bool, auth: list<string>, csrf: string|null, cache: array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null, rateLimit: array{limit: int, interval: string, policy: string, keyBy: string}|null, arguments: list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>}>
     */
    private function collectRows(): array
    {
        $rows = [];
        foreach ($this->registry->getRoutes() as $name => $route) {
            $authenticators = array_map(
                static fn (array $authenticator): string => $authenticator['service'],
                $this->registry->getAuthenticators($name),
            );

            $rows[$name] = [
                'name' => $name,
                'path' => $route['path'],
                'methods' => $route['methods'],
                'controller' => $route['controller'],
                'env' => $route['env'],
                'requirements' => $route['requirements'],
                'schemes' => $route['schemes'] ?? [],
                'host' => $route['host'] ?? null,
                'description' => $route['description'] ?? null,
                'caseInsensitive' => $route['caseInsensitive'] ?? false,
                'tags' => $route['tags'] ?? [],
                'canonical' => $this->registry->isCanonical($name),
                'auth' => $authenticators,
                'csrf' => $this->registry->getRequestTokenScope($name),
                'cache' => $this->registry->getCacheConfig($name),
                'rateLimit' => $this->registry->getRateLimit($name),
                'arguments' => $this->registry->getArguments($name),
            ];
        }

        return $rows;
    }

    /**
     * Active filters as label => predicate. The labels double as the human-readable summary.
     *
     * @return array<string, callable(array{name: string, path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes: list<string>, host: string|null, description: string|null, caseInsensitive: bool, tags: list<string>, canonical: bool, auth: list<string>, csrf: string|null, cache: array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null, rateLimit: array{limit: int, interval: string, policy: string, keyBy: string}|null, arguments: list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>}): bool>
     */
    private function activeFilters(InputInterface $input, ?string $name): array
    {
        $filters = [];

        if (null !== $name) {
            $filters['name~'.$name] = static fn (array $row): bool => str_contains($row['name'], $name);
        }

        $method = $this->stringOption($input, 'method');
        if (null !== $method) {
            $needle = strtoupper($method);
            $filters['method='.$needle] = static fn (array $row): bool => [] === $row['methods']
                || in_array($needle, array_map(strtoupper(...), $row['methods']), true);
        }

        $path = $this->stringOption($input, 'path');
        if (null !== $path) {
            $filters['path~'.$path] = static fn (array $row): bool => str_contains($row['path'], $path);
        }

        $env = $this->stringOption($input, 'env');
        if (null !== $env) {
            $filters['env='.$env] = static fn (array $row): bool => $env === $row['env'];
        }

        if (true === $input->getOption('unprotected')) {
            $filters['unprotected'] = static fn (array $row): bool => [] === $row['auth'];
        }
        if (true === $input->getOption('protected')) {
            $filters['protected'] = static fn (array $row): bool => [] !== $row['auth'];
        }
        if (true === $input->getOption('cached')) {
            $filters['cached'] = static fn (array $row): bool => null !== $row['cache'];
        }
        if (true === $input->getOption('rate-limited')) {
            $filters['rate-limited'] = static fn (array $row): bool => null !== $row['rateLimit'];
        }
        if (true === $input->getOption('csrf')) {
            $filters['csrf'] = static fn (array $row): bool => null !== $row['csrf'];
        }

        return $filters;
    }

    /**
     * @param array{name: string, path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes: list<string>, host: string|null, description: string|null, caseInsensitive: bool, tags: list<string>, canonical: bool, auth: list<string>, csrf: string|null, cache: array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null, rateLimit: array{limit: int, interval: string, policy: string, keyBy: string}|null, arguments: list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>}                                $row
     * @param array<string, callable(array{name: string, path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes: list<string>, host: string|null, description: string|null, caseInsensitive: bool, tags: list<string>, canonical: bool, auth: list<string>, csrf: string|null, cache: array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null, rateLimit: array{limit: int, interval: string, policy: string, keyBy: string}|null, arguments: list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>}): bool> $filters
     */
    private function matches(array $row, array $filters): bool
    {
        foreach ($filters as $predicate) {
            if (!$predicate($row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{name: string, path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes: list<string>, host: string|null, description: string|null, caseInsensitive: bool, tags: list<string>, canonical: bool, auth: list<string>, csrf: string|null, cache: array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null, rateLimit: array{limit: int, interval: string, policy: string, keyBy: string}|null, arguments: list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>} $row
     */
    private function renderDetail(SymfonyStyle $io, array $row): void
    {
        $io->title($row['name']);

        $cache = null === $row['cache']
            ? '-'
            : sprintf(
                'lifetime: %d, tags: %s, ignoreParams: %s',
                $row['cache']['lifetime'],
                [] === $row['cache']['tags'] ? '-' : implode(', ', $row['cache']['tags']),
                [] === $row['cache']['ignoreParams'] ? '-' : implode(', ', $row['cache']['ignoreParams']),
            );

        $rateLimit = null === $row['rateLimit']
            ? '-'
            : sprintf('limit: %d, interval: %s, policy: %s, keyBy: %s', $row['rateLimit']['limit'], $row['rateLimit']['interval'], $row['rateLimit']['policy'], $row['rateLimit']['keyBy']);

        $io->definitionList(
            ['Description' => $row['description'] ?? '-'],
            ['Path' => $row['path']],
            ['Methods' => [] === $row['methods'] ? 'ANY' : implode(', ', $row['methods'])],
            ['Controller' => $row['controller']],
            ['Env' => $row['env'] ?? '-'],
            ['Requirements' => RouteTableFormatter::requirements($row['requirements'])],
            ['Schemes' => [] === $row['schemes'] ? 'ANY' : implode(', ', $row['schemes'])],
            ['Host' => $row['host'] ?? '-'],
            ['Case insensitive' => $row['caseInsensitive'] ? 'yes' : 'no'],
            ['Tags' => RouteTableFormatter::joinOrDash($row['tags'])],
            ['Canonical redirect' => $row['canonical'] ? 'yes' : 'no'],
            ['Auth' => RouteTableFormatter::joinOrDash($row['auth'])],
            ['CSRF' => $row['csrf'] ?? '-'],
            ['Cache' => $cache],
            ['Rate limit' => $rateLimit],
            ['Arguments' => RouteTableFormatter::arguments($row['arguments'])],
        );
    }

    private function stringArgument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && '' !== $value ? $value : null;
    }
}
