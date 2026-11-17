<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ProfilePictureSource;

use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Repository\FeedRepository;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\Social\Support\ProfileFeedUrl;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Takes the pictures of Lemmy community and user feeds from their instance's API, asked again about weekly. */
#[AsTaggedItem(index: 'lemmy', priority: 10)]
final readonly class LemmyProfilePictures implements ProfilePictureSourceInterface
{
    public const string MOUSE = 'https://join-lemmy.org/static/assets/icons/apple-touch-icon.png';

    private const string REFRESH_AFTER = '-7 days';

    public function __construct(
        private FeedRepository $feeds,
        private LemmyApiClient $lemmy,
        private LoggerInterface $logger,
    ) {
    }

    public function refresh(ProfileCallBudgetModel $budget, \DateTimeImmutable $now): ProfileImageSweepReportModel
    {
        $due = $this->feeds->findProfileFeedsWithImageDue(
            SocialNetwork::Lemmy,
            $now->modify(self::REFRESH_AFTER),
            $budget->calls,
        );
        $report = ProfileImageSweepReportModel::none();
        foreach ($due as $feed) {
            $report = $report->adding($this->check($feed, $now));
        }

        return $report;
    }

    private function check(Feed $feed, \DateTimeImmutable $now): ProfileImageSweepReportModel
    {
        $identity = ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, $feed->getUrl());
        if (null === $identity) {
            $feed->recordImageCheck($feed->getImageUrl(), $now);

            return new ProfileImageSweepReportModel(1, 0);
        }

        try {
            $imageUrl = $this->pictureOf($identity);
        } catch (SocialPostNotFoundException) {
            $imageUrl = $feed->getImageUrl();
        } catch (
            SocialNetworkUnreachableException
            | SocialCredentialsRejectedException
            | SocialPostRefusedException $exception
        ) {
            $this->logger->warning('Lemmy profile picture check set aside a feed its instance refused.', [
                'feed' => $feed->getUrl(),
                'exception' => $exception,
            ]);
            $imageUrl = $feed->getImageUrl();
        }
        $changed = $imageUrl !== $feed->getImageUrl();
        $feed->recordImageCheck($imageUrl, $now);

        return new ProfileImageSweepReportModel(1, $changed ? 1 : 0, 1);
    }

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkUnreachableException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     */
    private function pictureOf(string $identity): ?string
    {
        $isUser = str_starts_with($identity, '@');
        [$name, $host] = explode('@', ltrim($identity, '@'), 2);
        $path = $isUser
            ? '/api/v3/user?username=' . rawurlencode($name) . '&limit=1'
            : '/api/v3/community?name=' . rawurlencode($name);
        $answer = JsonNodeModel::of($this->lemmy->send(ApiRequestModel::get('https://' . $host . $path))->json());
        $picture = $isUser
            ? $answer->node('person_view')->node('person')->string('avatar')
            : $answer->node('community_view')->node('community')->string('icon');
        $url = AbsoluteHttpUrl::httpsOrNull($picture);
        $url = null === $url ? null : AbsoluteHttpUrl::orNullWithin($url, Feed::IMAGE_URL_MAX);

        return $isUser ? ($url ?? self::MOUSE) : $url;
    }
}
