<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ProfilePictureSource;

use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Repository\FeedRepository;
use App\Service\Social\Bluesky\AppViewClient;
use App\Service\Social\Bluesky\Exception\AppViewAnswerException;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Social\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\Social\ProfileVisibilityCache;
use App\Service\Social\Social\Support\BlueskyProfileLabels;
use App\Service\Social\Social\Support\BlueskyProfileActor;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Fills and refreshes the pictures of Bluesky profile and author feeds, whose RSS or timeline carries none, from the
 * public AppView. A shared feed row, so the picture is the person's public avatar; asked again about weekly.
 */
#[AsTaggedItem(index: 'bluesky', priority: 20)]
final readonly class BlueskyProfilePictures implements ProfilePictureSourceInterface
{
    public function __construct(
        private FeedRepository $feeds,
        private AppViewClient $appView,
        private LoggerInterface $logger,
        private ProfileVisibilityCache $profileVisibility,
    ) {
    }

    public function refresh(ProfileCallBudgetModel $budget, \DateTimeImmutable $now): ProfileImageSweepReportModel
    {
        if ($budget->isEmpty() || $this->appView->isThrottled()) {
            return ProfileImageSweepReportModel::none();
        }

        $due = $this->feeds->findProfileFeedsWithImageDue(
            SocialNetwork::Bluesky,
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
                    $report = $report->adding(new ProfileImageSweepReportModel(0, 0, 1));
                    break;
                }
                $report = $report->adding($this->setAside($batch, $now, $exception));
            }
        }

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

        return new ProfileImageSweepReportModel(\count($batch), 0, 1);
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
        $calls = [] === $asked ? 0 : 1;
        $this->profileVisibility->remember($asked, BlueskyProfileLabels::hiddenAmong($profiles));
        $changed = 0;
        foreach ($batch as $index => $feed) {
            $profile = $profiles[$actors[$index] ?? ''] ?? null;
            $changed += $this->record($feed, $profile, $now) ? 1 : 0;
        }

        return new ProfileImageSweepReportModel(\count($batch), $changed, $calls);
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
            $actor = BlueskyProfileActor::parseFollowedActor($feed->getUrl());
            if (null !== $actor) {
                $actors[$index] = $actor;
            }
        }

        return $actors;
    }
}
