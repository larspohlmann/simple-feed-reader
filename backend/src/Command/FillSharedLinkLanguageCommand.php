<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\FeedLanguage;
use App\Service\Operations\LinkLanguageFill\Model\LinkLanguageFillReportModel;
use App\Service\Operations\LinkLanguageFill\SharedLinkLanguageFill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:shared-links:fill-language',
    description: 'Give every shared link without a language the one its preview decides (one-off, #2239)',
)]
final class FillSharedLinkLanguageCommand extends Command
{
    public function __construct(private readonly SharedLinkLanguageFill $fill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be filled and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('dry-run') === true) {
            $io->success('Would fill ' . self::summary($this->fill->count()));

            return Command::SUCCESS;
        }

        $io->success('Filled ' . self::summary($this->fill->fill()));

        return Command::SUCCESS;
    }

    private static function summary(LinkLanguageFillReportModel $report): string
    {
        return \sprintf(
            '%d shared links (%s); %d left without a language',
            $report->filledCount(),
            implode(', ', array_map(
                static fn (FeedLanguage $language): string => $language->value . ' ' . $report->filledIn($language),
                FeedLanguage::cases(),
            )),
            $report->leftWithout,
        );
    }
}
