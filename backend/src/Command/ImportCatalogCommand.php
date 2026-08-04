<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CatalogCategoryRepository;
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
 * environment — a developer's box, the e2e stack, a new production install —
 * can be seeded without clicking through the UI.
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
        private readonly CatalogCategoryRepository $categories,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to a catalog OPML document')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'merge or replace', CatalogImportMode::Merge->value)
            ->addOption('if-empty', null, InputOption::VALUE_NONE, 'Import only while the catalog is still empty');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mode = $this->mode($input);
        if (null === $mode) {
            $io->error('Mode must be "merge" or "replace".');

            return Command::FAILURE;
        }

        // The seeding guard the production start script relies on: it runs on
        // every start, and a catalog the admin has since edited is theirs.
        if ($this->skipBecauseCatalogExists($input)) {
            $io->info('The catalog already holds categories — imported nothing.');

            return Command::SUCCESS;
        }

        try {
            $document = $this->document($input);
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

    private function mode(InputInterface $input): ?CatalogImportMode
    {
        $value = $input->getOption('mode');

        return \is_string($value) ? CatalogImportMode::tryFrom($value) : null;
    }

    private function skipBecauseCatalogExists(InputInterface $input): bool
    {
        return (bool) $input->getOption('if-empty') && !$this->categories->isEmpty();
    }

    /** The document named by --file, or the one this release ships. */
    private function document(InputInterface $input): ParsedCatalog
    {
        $path = $input->getOption('file');

        return \is_string($path) && '' !== $path
            ? $this->read($path)
            : $this->bundled->document();
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
