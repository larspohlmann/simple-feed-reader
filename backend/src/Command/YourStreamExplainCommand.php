<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\YourStreamPrecision;
use App\Service\Curation\YourStream\Model\StreamWordModel;
use App\Service\Curation\YourStream\Model\YourStreamExplanationModel;
use App\Service\Curation\YourStream\YourStreamBackground;
use App\Service\Curation\YourStream\YourStreamExplainer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:your-stream:explain',
    description: 'Build one account\'s Your stream words in memory and replay its recent entries at every precision. '
        . 'Writes nothing.',
)]
final class YourStreamExplainCommand extends Command
{
    private const int DEFAULT_DAYS = 7;
    private const string CUT_FORM = 'cut form';

    public function __construct(private readonly YourStreamExplainer $explainer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'The user id whose Your stream is explained');
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of entries to replay');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = ConsoleOption::wholeNumber($input, 'user');
        if ($userId === null) {
            $io->error('The --user option is required: the id of the user whose Your stream is explained.');

            return Command::INVALID;
        }
        $days = max(1, ConsoleOption::wholeNumber($input, 'days') ?? self::DEFAULT_DAYS);
        $explanation = $this->explainer->buildExplanation($userId, $days);
        self::printWords($io, $explanation->words);
        self::printMatches($io, $explanation);

        return Command::SUCCESS;
    }

    /** @param list<StreamWordModel> $words */
    private static function printWords(SymfonyStyle $io, array $words): void
    {
        $wordsByLanguage = [];
        foreach ($words as $word) {
            $wordsByLanguage[$word->language->value ?? self::CUT_FORM][] = $word;
        }
        foreach ($wordsByLanguage as $language => $languageWords) {
            $io->section(\sprintf('%s: %d words', $language, \count($languageWords)));
            $io->table(['word', 'stem', 'weight', 'rarity'], array_map(
                static fn (StreamWordModel $word): array => [
                    $word->word,
                    $word->stem,
                    \sprintf('%.1f', $word->weight),
                    \sprintf('%.2f', $word->rarity),
                ],
                $languageWords,
            ));
        }
    }

    private static function printMatches(SymfonyStyle $io, YourStreamExplanationModel $explanation): void
    {
        $io->section(\sprintf(
            'Matches: %d entries over %d %s (cap %d)',
            $explanation->entryCount,
            $explanation->days,
            $explanation->days === 1 ? 'day' : 'days',
            YourStreamBackground::SAMPLE_ENTRIES,
        ));
        $io->table(
            ['precision', 'minimum score', 'matches (/day)'],
            array_map(
                static fn (YourStreamPrecision $precision): array => self::formatPrecisionRow($explanation, $precision),
                YourStreamPrecision::cases(),
            ),
        );
    }

    /** @return list<string> the step, its minimum score, then its matches with matches per day */
    private static function formatPrecisionRow(
        YourStreamExplanationModel $explanation,
        YourStreamPrecision $precision,
    ): array {
        return [
            $precision->value,
            \sprintf('%.1f', $precision->minimumScore()),
            \sprintf('%d (%.1f)', $explanation->countMatches($precision), $explanation->matchesPerDay($precision)),
        ];
    }
}
