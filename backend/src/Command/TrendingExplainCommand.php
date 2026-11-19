<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Entry;
use App\Enum\FeedLanguage;
use App\Enum\TrendingWindow;
use App\Exception\ValidationException;
use App\Repository\EntryRepository;
use App\Service\Curation\Trending\Model\LanguageCoverageModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\TrendingExplanationModel;
use App\Service\Curation\Trending\TrendingExplainer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:trending:explain',
    description: 'Group one Trending window in memory and print every group and the near misses. Writes nothing.',
)]
final class TrendingExplainCommand extends Command
{
    private const int NEAR_MISSES_SHOWN = 100;
    private const int TITLE_CHUNK = 500;
    private const int MEGABYTE = 1_048_576;

    public function __construct(
        private readonly TrendingExplainer $explainer,
        private readonly EntryRepository $entries,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'The user id whose included feeds are grouped');
        $this->addOption('window', null, InputOption::VALUE_REQUIRED, 'day or week', TrendingWindow::Day->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = ConsoleOption::wholeNumber($input, 'user');
        if ($userId === null) {
            $io->error('The --user option is required: the id of the user whose Trending feeds are grouped.');

            return Command::INVALID;
        }
        try {
            $window = TrendingWindow::fromRequestValue(ConsoleOption::text($input, 'window'));
        } catch (ValidationException $exception) {
            $io->error($exception->errors['window'] ?? [$exception->getMessage()]);

            return Command::FAILURE;
        }
        $explanation = $this->explainer->explain($userId, $window);
        $headlines = $this->loadHeadlines($explanation);
        $io->title(self::formatSummary($explanation));
        $io->writeln(self::formatLanguages($explanation->languageCoverage));
        $this->printGroups($io, $explanation, $headlines);
        $this->printNearMisses($io, $explanation, $headlines);

        return Command::SUCCESS;
    }

    /** @param array<int, string> $headlines */
    private function printGroups(SymfonyStyle $io, TrendingExplanationModel $explanation, array $headlines): void
    {
        foreach ($explanation->groups as $group) {
            $io->section(\sprintf('%d feeds, %d entries', $group->outletCount(), \count($group->members)));
            $io->listing(array_map(
                static fn (StoryMemberModel $member): string => $headlines[$member->entryId] ?? '#' . $member->entryId,
                $group->members,
            ));
        }
    }

    /** @param array<int, string> $headlines */
    private function printNearMisses(SymfonyStyle $io, TrendingExplanationModel $explanation, array $headlines): void
    {
        $shown = \array_slice($explanation->nearMisses, 0, self::NEAR_MISSES_SHOWN);
        $io->section(\sprintf('Near misses: %d of %d', \count($shown), \count($explanation->nearMisses)));
        foreach ($shown as $nearMiss) {
            $io->writeln(\sprintf(
                '%s%s    %s%s    %s',
                $nearMiss->failedRule,
                \PHP_EOL,
                $headlines[$nearMiss->overlap->earlierEntryId] ?? '',
                \PHP_EOL,
                $headlines[$nearMiss->overlap->entryId] ?? '',
            ));
        }
    }

    /** @return array<int, string> "[feed] headline" by entry id, for every entry the explanation prints */
    private function loadHeadlines(TrendingExplanationModel $explanation): array
    {
        $headlines = [];
        foreach (array_chunk(self::printedEntryIds($explanation), self::TITLE_CHUNK) as $entryIds) {
            foreach ($this->entries->findAllByIds($entryIds) as $entry) {
                $headlines[$entry->requireId()] = self::formatHeadline($entry);
            }
        }

        return $headlines;
    }

    /** @return list<int> */
    private static function printedEntryIds(TrendingExplanationModel $explanation): array
    {
        $entryIds = [];
        foreach ($explanation->groups as $group) {
            foreach ($group->members as $member) {
                $entryIds[] = $member->entryId;
            }
        }
        foreach (\array_slice($explanation->nearMisses, 0, self::NEAR_MISSES_SHOWN) as $nearMiss) {
            array_push($entryIds, $nearMiss->overlap->earlierEntryId, $nearMiss->overlap->entryId);
        }

        return array_values(array_unique($entryIds));
    }

    private static function formatHeadline(Entry $entry): string
    {
        return \sprintf('[%s] %s', $entry->getFeed()->getTitle() ?? $entry->getFeed()->getUrl(), $entry->getTitle());
    }

    private static function formatLanguages(LanguageCoverageModel $coverage): string
    {
        $counts = array_map(
            static fn (FeedLanguage $language): string
                => \sprintf('%s %d', $language->value, $coverage->entriesIn($language)),
            FeedLanguage::cases(),
        );

        return \sprintf('Languages: %s, none %d', implode(', ', $counts), $coverage->entriesWithoutLanguage);
    }

    private static function formatSummary(TrendingExplanationModel $explanation): string
    {
        return \sprintf(
            'Trending %s: %d entries, %d groups, %d ms, peak %.1f MB',
            $explanation->window->value,
            $explanation->entryCount,
            \count($explanation->groups),
            $explanation->durationMilliseconds,
            $explanation->peakMemoryBytes / self::MEGABYTE,
        );
    }
}
