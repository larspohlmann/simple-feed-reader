<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Repository\FeedRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills and refreshes the pictures of Bluesky profile feeds, whose RSS carries none, from the public AppView. A shared
 * feed row, so the picture is the person's public avatar; asked again about weekly.
 */
final readonly class ProfileImageSweep
{
    private const string REFRESH_AFTER = '-7 days';

    public function __construct(
        private FeedRepository $feeds,
        private AppViewClient $appView,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(ProfileImageBudgetModel $budget): ProfileImageSweepReportModel
    {
        if ($budget->isEmpty() || $this->appView->isThrottled()) {
            return ProfileImageSweepReportModel::none();
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $due = $this->feeds->findBlueskyProfileFeedsWithImageDue(
            $now->modify(self::REFRESH_AFTER),
            $budget->calls * AppViewClient::URIS_PER_CALL,
        );
        $report = ProfileImageSweepReportModel::none();
        foreach (array_chunk($due, AppViewClient::URIS_PER_CALL) as $batch) {
            try {
                $report = $report->adding($this->check($batch, $now));
            } catch (FetchException | AppViewAnswerException $exception) {
                $this->logger->warning('Profile picture sweep stopped: the AppView did not answer.', [
                    'exception' => $exception,
                ]);
                break;
            }
        }
        $this->entityManager->flush();

        return $report;
    }

    /**
     * @param list<Feed> $batch
     *
     * @throws FetchException
     * @throws AppViewAnswerException
     */
    private function check(array $batch, \DateTimeImmutable $now): ProfileImageSweepReportModel
    {
        $actors = self::actorsOf($batch);
        $profiles = [] === $actors ? [] : $this->appView->profiles(array_values(array_unique($actors)));
        $changed = 0;
        foreach ($batch as $index => $feed) {
            $profile = $profiles[$actors[$index] ?? ''] ?? null;
            $changed += $this->record($feed, $profile, $now) ? 1 : 0;
        }

        return new ProfileImageSweepReportModel(\count($batch), $changed);
    }

    /** @return bool whether the stored picture changed */
    private function record(Feed $feed, ?JsonNodeModel $profile, \DateTimeImmutable $now): bool
    {
        if (null === $profile) {
            $feed->markImageCheckedAt($now);

            return false;
        }
        $imageUrl = AbsoluteHttpUrl::orNullWithin($profile->string('avatar'), Feed::IMAGE_URL_MAX);
        $changed = $imageUrl !== $feed->getImageUrl();
        $feed->recordImageCheck($imageUrl, $now);

        return $changed;
    }

    /**
     * @param list<Feed> $batch
     *
     * @return array<int, string> the actor each profile feed follows, by its position in $batch
     */
    private static function actorsOf(array $batch): array
    {
        $actors = [];
        foreach ($batch as $index => $feed) {
            $actor = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $feed->getUrl());
            if (null !== $actor) {
                $actors[$index] = $actor;
            }
        }

        return $actors;
    }
}
