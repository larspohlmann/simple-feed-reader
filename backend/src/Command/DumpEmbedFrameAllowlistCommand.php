<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Reader\Media\EmbedProviders;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes the reader client's embed allow-list from the backend providers, so the
 * two never drift: the frontend upgrades a link to an `<iframe>` only when its
 * URL matches one of these patterns, and each pattern is a provider's own
 * `framePattern()` (#1048). Run this after adding or changing an embed provider;
 * `EmbedFrameAllowlistTest` fails the build until the committed file is current.
 */
#[AsCommand(
    name: 'app:embed:dump-frame-allowlist',
    description: 'Regenerate the reader client embed allow-list from the embed providers.',
)]
final class DumpEmbedFrameAllowlistCommand extends Command
{
    public function __construct(
        private readonly EmbedProviders $providers,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    public static function allowlistPath(string $projectDir): string
    {
        return \dirname($projectDir) . '/frontend/src/app/reader/embed-frame-allowlist.generated.json';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = self::allowlistPath($this->projectDir);
        file_put_contents($path, $this->providers->allowlistJson());

        (new SymfonyStyle($input, $output))->success('Wrote the reader embed allow-list to ' . $path . '.');

        return Command::SUCCESS;
    }
}
