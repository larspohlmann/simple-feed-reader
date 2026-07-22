<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Subscription\SubscribeRequest;
use App\Dto\Subscription\UpdateSubscriptionRequest;
use App\Entity\User;
use App\Http\SubscriptionJson;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/subscriptions')]
final class SubscriptionController
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly TagRepository $tags,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_subscriptions_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->subscriptionRepo->findForUserWithTags((int) $user->getId());
        $counts = $this->subscriptionRepo->unreadCountsForUser((int) $user->getId());

        return new JsonResponse([
            'subscriptions' => array_map(
                static fn ($s) => SubscriptionJson::one($s, $counts[(int) $s->getId()] ?? 0),
                $rows,
            ),
        ]);
    }

    #[Route('', name: 'api_subscriptions_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] SubscribeRequest $request): JsonResponse
    {
        $outcome = $this->subscriptions->subscribe($user, $request->url);

        if (null === $outcome->subscription) {
            return new JsonResponse([
                'candidates' => array_map(
                    static fn ($c) => ['url' => $c->url, 'title' => $c->title],
                    $outcome->candidates,
                ),
            ]);
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

        // Replace the tag set with the requested (user-owned) tags.
        $resolved = $this->tags->findAllByIdsForUser($request->tagIds, (int) $user->getId());
        foreach ($sub->getTags()->toArray() as $existing) {
            $sub->removeTag($existing);
        }
        foreach ($resolved as $tag) {
            $sub->addTag($tag);
        }

        $this->em->flush();

        return new JsonResponse(['subscription' => SubscriptionJson::one($sub)]);
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
