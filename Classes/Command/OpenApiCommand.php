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

use KonradMichalik\Typo3Routing\OpenApi\OpenApiGenerator;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function is_string;
use function json_encode;

/**
 * OpenApiCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(name: 'routing:openapi', description: 'Export all registered attribute routes as an OpenAPI 3.1 document')]
final class OpenApiCommand extends Command
{
    public function __construct(
        private readonly OpenApiGenerator $generator,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'The API title', 'TYPO3 Routing API');
        $this->addOption('api-version', null, InputOption::VALUE_REQUIRED, 'The API version', '1.0.0');
        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'The server base URL (defaults to the configured path prefix)');
        $this->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty-print the JSON output');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $document = $this->generator->generate(
            $this->stringOption($input, 'title', 'TYPO3 Routing API'),
            $this->stringOption($input, 'api-version', '1.0.0'),
            $this->stringOption($input, 'server', $this->defaultServer()),
        );

        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES;
        if (true === $input->getOption('pretty')) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        $output->writeln(json_encode($document, $flags));

        return Command::SUCCESS;
    }

    private function defaultServer(): string
    {
        try {
            $prefix = $this->extensionConfiguration->get('typo3_routing', 'prefix');

            return is_string($prefix) ? $prefix : '/api/';
        } catch (Throwable) {
            return '/api/';
        }
    }

    private function stringOption(InputInterface $input, string $name, string $default): string
    {
        $value = $input->getOption($name);

        return is_string($value) && '' !== $value ? $value : $default;
    }
}
