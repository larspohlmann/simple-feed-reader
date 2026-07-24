<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Subscription\ReorderSubscriptionsRequest;
use App\Dto\Subscription\SubscribeRequest;
use App\Dto\Subscription\UpdateSubscriptionRequest;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Http\SubscriptionJson;
use App\Repository\EntryStateRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/subscriptions')]
final class SubscriptionController
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly SubscriptionTagRepository $subscriptionTags,
        private readonly TagRepository $tags,
        private readonly EntryStateRepository $entryStates,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_subscriptions_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->subscriptionRepo->findForUserWithTags((int) $user->getId());
        $counts = $this->subscriptionRepo->unreadCountsForUser((int) $user->getId());
        $flags = $this->entryStates->favoriteAndKeptCountsForUser((int) $user->getId());

        return new JsonResponse([
            'subscriptions' => array_map(
                static fn ($s) => SubscriptionJson::one($s, $counts[(int) $s->getId()] ?? 0),
                $rows,
            ),
            'favoritesCount' => $flags['favorites'],
            'keptCount' => $flags['kept'],
        ]);
    }

    #[Route('', name: 'api_subscriptions_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] SubscribeRequest $request): JsonResponse
    {
        $outcome = $this->subscriptions->subscribe($user, $request->url, $request->format);

        if (null === $outcome->subscription) {
            $payload = [
                'candidates' => array_map(
                    static fn ($c) => ['url' => $c->url, 'title' => $c->title, 'format' => $c->format],
                    $outcome->candidates,
                ),
            ];
            // Key present only on failure: successful candidate lists stay
            // byte-compatible with what pre-scraper clients already parse.
            if (null !== $outcome->scrapeFailureReason) {
                $payload['scrapeFailureReason'] = $outcome->scrapeFailureReason;
            }

            return new JsonResponse($payload);
        }

        return new JsonResponse(
            ['subscription' => SubscriptionJson::one($outcome->subscription)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'api_subscriptions_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateSubscriptionRequest $request,
    ): JsonResponse {
        $sub = $this->subscriptionRepo->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such subscription.');

        $sub->setCustomTitle('' === (string) $request->customTitle ? null : $request->customTitle);

        $wasTagged = !$sub->getTags()->isEmpty();

        // Sync the tag set to the requested (user-owned) tags by DIFF, not
        // clear-and-re-add: a tag the feed keeps must retain its per-tag
        // position, and a newly added tag appends to the end of that tag's list.
        $resolved = $this->tags->findAllByIdsForUser($request->tagIds, (int) $user->getId());
        $resolvedIds = array_map(static fn (Tag $t): int => (int) $t->getId(), $resolved);

        foreach ($sub->getTags() as $existing) {
            if (!\in_array((int) $existing->getId(), $resolvedIds, true)) {
                $sub->removeTag($existing);
            }
        }
        $currentIds = array_map(static fn (Tag $t): int => (int) $t->getId(), $sub->getTags()->toArray());
        foreach ($resolved as $tag) {
            if (!\in_array((int) $tag->getId(), $currentIds, true)) {
                $sub->addTag($tag, $this->subscriptionTags->nextPositionForTag($tag));
            }
        }

        // A feed that just lost its last tag joins the untagged "Feeds" list;
        // append it so its stale position doesn't float it to the top.
        if ($wasTagged && $sub->getTags()->isEmpty()) {
            $sub->setPosition($this->subscriptionRepo->nextPositionForUser((int) $user->getId()));
        }

        $this->em->flush();

        return new JsonResponse(['subscription' => SubscriptionJson::one($sub)]);
    }

    /**
     * Persist the order of the untagged "Feeds" list. The body lists the feeds
     * in their new order; each feed's position becomes its index. Ids must be
     * owned by the user.
     */
    #[Route('/reorder', name: 'api_subscriptions_reorder', methods: ['PATCH'])]
    public function reorder(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ReorderSubscriptionsRequest $request,
    ): JsonResponse {
        $owned = $this->subscriptionRepo->findAllByIdsForUser($request->subscriptionIds, (int) $user->getId());
        if (\count($owned) !== \count(array_unique($request->subscriptionIds))) {
            throw new UnprocessableEntityHttpException('subscriptionIds must all be your feeds, without duplicates.');
        }

        /** @var array<int, Subscription> $byId */
        $byId = [];
        foreach ($owned as $sub) {
            $byId[(int) $sub->getId()] = $sub;
        }
        foreach ($request->subscriptionIds as $index => $subscriptionId) {
            $byId[$subscriptionId]->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'api_subscriptions_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $sub = $this->subscriptionRepo->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such subscription.');

        $this->em->remove($sub);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
