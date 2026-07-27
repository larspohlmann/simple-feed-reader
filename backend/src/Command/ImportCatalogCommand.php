<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\BundledCatalog;
use App\Service\Catalog\CatalogDocument;
use App\Service\Catalog\CatalogImporter;
use App\Service\Catalog\CatalogImportMode;
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use App\Service\Catalog\ParsedCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports a catalog document from disk, defaulting to the one this release
 * ships. The admin area is the primary route; this exists so a fresh
 * environment — a developer's box, the e2e stack — can be seeded without
 * clicking through the UI.
 */
#[AsCommand(
    name: 'app:catalog:import',
    description: 'Import a catalog OPML document',
)]
final class ImportCatalogCommand extends Command
{
    public function __construct(
        private readonly CatalogImporter $importer,
        private readonly CatalogDocument $parser,
        private readonly BundledCatalog $bundled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to a catalog OPML document')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'merge or replace', CatalogImportMode::Merge->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $modeOption = $input->getOption('mode');
        $mode = \is_string($modeOption) ? CatalogImportMode::tryFrom($modeOption) : null;
        if (null === $mode) {
            $io->error('Mode must be "merge" or "replace".');

            return Command::FAILURE;
        }

        $fileOption = $input->getOption('file');
        $explicitPath = \is_string($fileOption) && '' !== $fileOption ? $fileOption : null;

        try {
            $document = null === $explicitPath
                ? $this->bundled->document()
                : $this->read($explicitPath);
        } catch (InvalidCatalogDocumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $result = $this->importer->import($document, $mode);

        $io->success(\sprintf(
            'Catalog imported (%s): categories +%d ~%d -%d, feeds +%d ~%d -%d.',
            $mode->value,
            $result->categoriesCreated,
            $result->categoriesUpdated,
            $result->categoriesRemoved,
            $result->feedsCreated,
            $result->feedsUpdated,
            $result->feedsRemoved,
        ));

        return Command::SUCCESS;
    }

    private function read(string $path): ParsedCatalog
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidCatalogDocumentException(
                \sprintf('No readable catalog document at %s.', $path),
            );
        }

        return $this->parser->parse((string) file_get_contents($path));
    }
}
