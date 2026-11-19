<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ProfilePictureSource;

use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Repository\FeedRepository;
use App\Service\Foundation\Url\Support\HttpsImageUrl;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\Social\Support\LemmyProfileFeed;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'lemmy', priority: 10)]
final readonly class LemmyProfilePictures implements ProfilePictureSourceInterface
{
    public const string MOUSE = 'https://join-lemmy.org/static/assets/icons/apple-touch-icon.png';

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
        $identity = LemmyProfileFeed::parseIdentity($feed->getUrl());
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
            $this->logger->warning('Lemmy profile picture check set aside a feed its instance did not answer usably.', [
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
        $user = LemmyProfileFeed::parseUser($identity);
        if (null !== $user) {
            return $this->userPictureOf($user);
        }

        return $this->communityPictureOf($identity);
    }

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkUnreachableException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     */
    private function communityPictureOf(string $identity): ?string
    {
        [$name, $host] = $this->splitAtLastAt($identity);
        $answer = $this->answerOf($host, '/api/v3/community?name=' . rawurlencode($name), 'community_view');
        $icon = $answer->node('community_view')->node('community')->string('icon');

        return HttpsImageUrl::orNull($icon);
    }

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkUnreachableException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     */
    private function userPictureOf(string $identity): ?string
    {
        [$name, $host] = $this->splitAtLastAt($identity);
        $answer = $this->answerOf($host, '/api/v3/user?username=' . rawurlencode($name) . '&limit=1', 'person_view');
        $avatar = $answer->node('person_view')->node('person')->string('avatar');
        if (null === $avatar) {
            return self::MOUSE;
        }

        return HttpsImageUrl::orNull($avatar);
    }

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkUnreachableException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     */
    private function answerOf(string $host, string $path, string $view): JsonNodeModel
    {
        $answer = JsonNodeModel::of($this->lemmy->send(ApiRequestModel::get('https://' . $host . $path))->json());
        if (!$answer->has($view)) {
            throw new SocialNetworkUnreachableException('The Lemmy instance answered without a ' . $view . '.');
        }

        return $answer;
    }

    /** @return array{string, string} */
    private function splitAtLastAt(string $identity): array
    {
        $parts = explode('@', $identity);
        $host = array_pop($parts);

        return [implode('@', $parts), $host];
    }
}
