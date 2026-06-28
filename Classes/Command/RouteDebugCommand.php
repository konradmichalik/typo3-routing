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
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RouteDebugCommand.
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
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the routes as JSON (machine-readable)');
        $this->addOption('unprotected', null, InputOption::VALUE_NONE, 'List only routes without an authenticator (audit: "show me every open endpoint")');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $unprotectedOnly = true === $input->getOption('unprotected');
        $rows = $this->collectRows($unprotectedOnly);

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        if ([] === $rows) {
            $io->warning($unprotectedOnly ? 'No unprotected attribute routes.' : 'No attribute routes registered.');

            return Command::SUCCESS;
        }

        $io->title($unprotectedOnly ? 'Unprotected Attribute Routes' : 'Attribute Routes');
        $io->table(
            ['Name', 'Path', 'Methods', 'Controller', 'Env', 'Requirements', 'Auth', 'CSRF'],
            array_map(self::toTableRow(...), $rows),
        );

        return Command::SUCCESS;
    }

    /**
     * @return list<array{name: string, path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, auth: list<string>, csrf: string|null}>
     */
    private function collectRows(bool $unprotectedOnly): array
    {
        $rows = [];
        foreach ($this->registry->getRoutes() as $name => $route) {
            $authenticators = array_map(
                static fn (array $authenticator): string => $authenticator['service'],
                $this->registry->getAuthenticators($name),
            );

            if ($unprotectedOnly && [] !== $authenticators) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'path' => $route['path'],
                'methods' => $route['methods'],
                'controller' => $route['controller'],
                'env' => $route['env'],
                'requirements' => $route['requirements'],
                'auth' => $authenticators,
                'csrf' => $this->registry->getRequestTokenScope($name),
            ];
        }

        return $rows;
    }

    /**
     * @param array{name: string, path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, auth: list<string>, csrf: string|null} $row
     *
     * @return list<string>
     */
    private static function toTableRow(array $row): array
    {
        $requirements = [];
        foreach ($row['requirements'] as $parameter => $pattern) {
            $requirements[] = $parameter.': '.$pattern;
        }

        return [
            $row['name'],
            $row['path'],
            implode(', ', $row['methods']),
            $row['controller'],
            $row['env'] ?? '-',
            [] === $requirements ? '-' : implode(', ', $requirements),
            [] === $row['auth'] ? '-' : implode(', ', $row['auth']),
            $row['csrf'] ?? '-',
        ];
    }
}
