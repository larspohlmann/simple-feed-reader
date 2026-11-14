<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\Support\BlueskyProfileLabels;
use App\Service\Social\Support\ProfileIdentity;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills and refreshes the pictures of Bluesky profile and author feeds, whose RSS or timeline carries none, from the
 * public AppView. A shared feed row, so the picture is the person's public avatar; asked again about weekly.
 */
final readonly class ProfileImageSweep
{
    private const string REFRESH_AFTER = '-7 days';

    public function __construct(
        private FeedRepository $feeds,
        private AppViewClient $appView,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
        private ProfileVisibilityCache $profileVisibility,
    ) {
    }

    public function sweep(ProfileCallBudgetModel $budget): ProfileImageSweepReportModel
    {
        if ($budget->isEmpty() || $this->appView->isThrottled()) {
            return ProfileImageSweepReportModel::none();
        }

        $now = $this->clock->now();
        $due = $this->feeds->findBlueskyProfileFeedsWithImageDue(
            $now->modify(self::REFRESH_AFTER),
            $budget->calls * AppViewClient::URIS_PER_CALL,
        );
        $report = ProfileImageSweepReportModel::none();
        foreach (array_chunk($due, AppViewClient::URIS_PER_CALL) as $batch) {
            try {
                $report = $report->adding($this->check($batch, $now));
            } catch (AppViewAnswerException $exception) {
                $report = $report->adding($this->setAside($batch, $now, $exception));
            } catch (FetchException $exception) {
                if (!AppViewClient::isRejection($exception)) {
                    $this->logger->warning('Profile picture sweep stopped: the AppView did not answer.', [
                        'exception' => $exception,
                    ]);
                    break;
                }
                $report = $report->adding($this->setAside($batch, $now, $exception));
            }
        }
        $this->entityManager->flush();

        return $report;
    }

    /**
     * Stamped as asked, so a batch the AppView keeps refusing moves behind the others instead of blocking them.
     *
     * @param list<Feed> $batch
     */
    private function setAside(array $batch, \DateTimeImmutable $now, \Exception $refusal): ProfileImageSweepReportModel
    {
        $this->logger->warning('Profile picture sweep set aside a batch the AppView refused.', [
            'exception' => $refusal,
        ]);
        foreach ($batch as $feed) {
            $feed->recordImageCheck($feed->getImageUrl(), $now);
        }

        return new ProfileImageSweepReportModel(\count($batch), 0);
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
        $asked = array_values(array_unique($actors));
        $profiles = [] === $asked ? [] : $this->appView->profiles($asked);
        $this->profileVisibility->remember($asked, BlueskyProfileLabels::hiddenAmong($profiles));
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
            $feed->recordImageCheck($feed->getImageUrl(), $now);

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
            $actor = ProfileIdentity::validBlueskyActorOf($feed->getUrl());
            if (null !== $actor) {
                $actors[$index] = $actor;
            }
        }

        return $actors;
    }
}
