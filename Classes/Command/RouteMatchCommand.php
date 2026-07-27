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
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;

use function implode;
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
        private readonly RouteRegistry $registry,
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
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = '/'.ltrim((string) $input->getArgument('path'), '/');
        $context = $this->context($input);

        try {
            $match = $this->registry->getMatcher($context)->match($path);
        } catch (ResourceNotFoundException) {
            $io->error(sprintf('No route matches "%s %s" (scheme %s, host %s).', $context->getMethod(), $path, $context->getScheme(), $context->getHost()));

            return Command::FAILURE;
        } catch (MethodNotAllowedException $exception) {
            $io->warning(sprintf('Path "%s" matches, but method "%s" is not allowed. Allowed: %s.', $path, $context->getMethod(), implode(', ', $exception->getAllowedMethods())));

            return Command::FAILURE;
        }

        $this->render($io, $match);

        return Command::SUCCESS;
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

        $env = $match['_env'] ?? null;
        if (is_string($env) && '' !== $env) {
            // The matcher ignores env; the dispatcher hides the route (404) outside this context.
            $rows[] = ['Env' => $env.' (only reachable in this application context)'];
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
