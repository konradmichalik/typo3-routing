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

use KonradMichalik\Typo3Routing\Routing\{RouteLinter, RouteRegistry};
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function array_map;
use function count;
use function is_string;
use function sprintf;
use function strtoupper;

/**
 * RouteLintCommand.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(name: 'routing:lint', description: 'Audit registered attribute routes for common mistakes')]
final class RouteLintCommand extends Command
{
    private readonly string $exclusivePrefixes;

    public function __construct(
        private readonly RouteRegistry $registry,
        private readonly RouteLinter $linter,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        parent::__construct();

        $configured = '';
        try {
            $value = $extensionConfiguration->get('typo3_routing', 'exclusivePrefixes');
            if (is_string($value)) {
                $configured = $value;
            }
        } catch (Throwable) {
            // Extension not configured yet — then no path space is claimed exclusively.
        }

        $this->exclusivePrefixes = $configured;
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output findings as JSON (machine-readable)');
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail (exit 1) when any finding exists, not only error-level ones');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $findings = $this->linter->lint($this->registry, $this->exclusivePrefixes);
        $strict = true === $input->getOption('strict');

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($findings, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return $this->exitCode($findings, $strict);
        }

        $io = new SymfonyStyle($input, $output);

        if ([] === $findings) {
            $io->success('No findings. The registered routes look clean.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Severity', 'Check', 'Route', 'Controller', 'Message'],
            array_map(static fn (array $finding): array => [
                strtoupper($finding['severity']),
                $finding['check'],
                $finding['route'] ?? '-',
                $finding['controller'] ?? '-',
                $finding['message'],
            ], $findings),
        );
        $io->warning(sprintf('%d finding(s).', count($findings)));

        return $this->exitCode($findings, $strict);
    }

    /**
     * No check currently reaches `error` severity (see `RouteLinter`) — until one does, `--strict` is
     * the only way to fail the build on a finding.
     *
     * @param list<array{severity: string, check: string, route: string|null, controller: string|null, message: string}> $findings
     */
    private function exitCode(array $findings, bool $strict): int
    {
        return $strict && [] !== $findings ? Command::FAILURE : Command::SUCCESS;
    }
}
