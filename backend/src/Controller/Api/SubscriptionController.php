<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Subscription\BulkUnsubscribeRequest;
use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use App\Dto\Subscription\ReorderSubscriptionsRequest;
use App\Dto\Subscription\SubscribeRequest;
use App\Dto\Subscription\UpdateSubscriptionRequest;
use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\ScrapingDisabledApiException;
use App\Http\SubscriptionCountsJson;
use App\Http\SubscriptionJson;
use App\Repository\EntryStateRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Discovery\Exception\ScrapingDisabledException;
use App\Service\Subscription\BulkSubscriptionUpdater;
use App\Service\Subscription\OwnedSubscriptions;
use App\Service\Subscription\SubscriptionService;
use App\Service\Subscription\SubscriptionTagSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/subscriptions')]
final readonly class SubscriptionController
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private SubscriptionRepository $subscriptionRepo,
        private SubscriptionTagSync $tagSync,
        private TagRepository $tags,
        private EntryStateRepository $entryStates,
        private EntityManagerInterface $em,
        private OwnedSubscriptions $ownedSubscriptions,
        private BulkSubscriptionUpdater $bulkUpdater,
    ) {
    }

    #[Route('', name: 'api_subscriptions_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->subscriptionRepo->findForUserWithTags((int) $user->getId());
        $counts = $this->entryStates->unreadCountsForUser((int) $user->getId());
        $flags = $this->entryStates->stateCountsForUser((int) $user->getId());

        return new JsonResponse([
            'subscriptions' => array_map(
                static fn ($s) => SubscriptionJson::one($s, $counts[(int) $s->getId()] ?? 0),
                $rows,
            ),
            'favoritesCount' => $flags['favorites'],
            'keptCount' => $flags['kept'],
            'viewedCount' => $flags['viewed'],
        ]);
    }

    /**
     * The sidebar poll's cheap tick (#720): unread counts and the three surface
     * totals, without hydrating feeds, tags or descriptions. Route declared
     * before `/{id}`, which requires a numeric id, so `/counts` reaches here.
     */
    #[Route('/counts', name: 'api_subscriptions_counts', methods: ['GET'])]
    public function counts(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(SubscriptionCountsJson::from(
            $this->entryStates->unreadCountsForUser((int) $user->getId()),
            $this->entryStates->stateCountsForUser((int) $user->getId()),
        ));
    }

    #[Route('', name: 'api_subscriptions_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] SubscribeRequest $request): JsonResponse
    {
        $tags = $this->tags->findAllByIdsForUser($request->tagIds, (int) $user->getId());

        try {
            $outcome = $this->subscriptions->subscribe($user, $request->url, $request->format, $tags, $request->title);
        } catch (ScrapingDisabledException $e) {
            // Rethrow as an ApiException so the listener renders a problem+json
            // document — a bare RuntimeException would otherwise reach
            // ApiExceptionListener's unhandled branch and 500.
            throw new ScrapingDisabledApiException($e->getMessage(), $e);
        }

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

        // A new subscription is no longer always worth 0 unread: discovery
        // hands the feed its entries at subscribe time (#290).
        return new JsonResponse(
            ['subscription' => SubscriptionJson::one($outcome->subscription, $outcome->unreadCount)],
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

        $this->tagSync->sync($sub, $request->tagIds, (int) $user->getId());

        // null on either flag means "leave the stored value unchanged", matching
        // EntryController::updateState()'s nullable-PATCH convention (#695).
        if (null !== $request->includeInAllItems) {
            $sub->setIncludeInAllItems($request->includeInAllItems);
        }
        if (null !== $request->includeInForYou) {
            $sub->setIncludeInForYou($request->includeInForYou);
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
        $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, (int) $user->getId());

        foreach ($request->subscriptionIds as $index => $subscriptionId) {
            $byId[$subscriptionId]->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Change tags and inclusion flags across many feeds in one request. Every
     * id must be the caller's; one that is not answers 422 and writes nothing.
     */
    #[Route('/bulk', name: 'api_subscriptions_bulk_update', methods: ['PATCH'])]
    public function bulkUpdate(
        #[CurrentUser] User $user,
        #[MapRequestPayload] BulkUpdateSubscriptionsRequest $request,
    ): JsonResponse {
        $changed = $this->bulkUpdater->apply($request, (int) $user->getId());

        return new JsonResponse([
            'subscriptions' => array_map(
                static fn (Subscription $subscription): array => SubscriptionJson::one($subscription),
                $changed,
            ),
        ]);
    }

    /**
     * Unsubscribe from many feeds in one request. No undo: the entries go with
     * the subscription, so the client's confirmation is the only guard.
     */
    #[Route('/bulk-unsubscribe', name: 'api_subscriptions_bulk_unsubscribe', methods: ['POST'])]
    public function bulkUnsubscribe(
        #[CurrentUser] User $user,
        #[MapRequestPayload] BulkUnsubscribeRequest $request,
    ): JsonResponse {
        $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, (int) $user->getId());

        return new JsonResponse(['removed' => $this->subscriptions->unsubscribeAll(array_values($byId))]);
    }

    #[Route('/{id}', name: 'api_subscriptions_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $subscription = $this->subscriptionRepo->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such subscription.');

        $this->subscriptions->unsubscribe($subscription);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
