<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Tag\CreateTagRequest;
use App\Dto\Tag\ReorderTagsRequest;
use App\Dto\Tag\TagFeedOrderRequest;
use App\Dto\Tag\UpdateTagRequest;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\TagNameTakenException;
use App\Http\TagJson;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/tags')]
final class TagController
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionTagRepository $subscriptionTags,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_tags_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->tags->findForUser((int) $user->getId());

        return new JsonResponse([
            'tags' => array_map(static fn (Tag $t) => TagJson::one($t), $rows),
        ]);
    }

    #[Route('', name: 'api_tags_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] CreateTagRequest $request): JsonResponse
    {
        if ($this->tags->existsForUserAndName((int) $user->getId(), $request->name)) {
            throw new TagNameTakenException();
        }

        $tag = new Tag($user, $request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $tag->setPosition($this->tags->nextPositionForUser((int) $user->getId()));
        $this->em->persist($tag);
        $this->em->flush();

        return new JsonResponse(['tag' => TagJson::one($tag)], Response::HTTP_CREATED);
    }

    /**
     * Persist the sidebar tag order. The body must list exactly the user's tags;
     * each tag's position becomes its index.
     */
    #[Route('/reorder', name: 'api_tags_reorder', methods: ['PATCH'])]
    public function reorder(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ReorderTagsRequest $request,
    ): JsonResponse {
        $owned = $this->tags->findForUser((int) $user->getId());
        /** @var array<int, Tag> $byId */
        $byId = [];
        foreach ($owned as $tag) {
            $byId[(int) $tag->getId()] = $tag;
        }

        $this->assertExactSet($request->tagIds, array_keys($byId), 'tagIds must list exactly your tags.');

        foreach ($request->tagIds as $index => $tagId) {
            $byId[$tagId]->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse([
            'tags' => array_map(fn (int $id): array => TagJson::one($byId[$id]), $request->tagIds),
        ]);
    }

    /**
     * Persist the order of feeds within one tag. The body must list exactly the
     * feeds currently carrying the tag; each feed's per-tag position becomes its
     * index.
     */
    #[Route('/{id}/feed-order', name: 'api_tags_feed_order', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function feedOrder(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] TagFeedOrderRequest $request,
    ): JsonResponse {
        $tag = $this->tags->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such tag.');

        $joinsBySubId = $this->subscriptionTags->forTagBySubscriptionId($tag);
        $this->assertExactSet(
            $request->subscriptionIds,
            array_keys($joinsBySubId),
            "subscriptionIds must list exactly this tag's feeds.",
        );

        foreach ($request->subscriptionIds as $index => $subscriptionId) {
            $joinsBySubId[$subscriptionId]->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'api_tags_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateTagRequest $request,
    ): JsonResponse {
        $tag = $this->tags->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such tag.');

        if ($this->tags->existsForUserAndName((int) $user->getId(), $request->name, $id)) {
            throw new TagNameTakenException();
        }

        $tag->setName($request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $this->em->flush();

        return new JsonResponse(['tag' => TagJson::one($tag)]);
    }

    #[Route('/{id}', name: 'api_tags_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $tag = $this->tags->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such tag.');

        // Detach from every subscription first (portable across SQLite/MySQL).
        foreach ($this->subscriptions->findByTag($tag) as $sub) {
            $sub->removeTag($tag);
        }
        $this->em->remove($tag);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * A reorder must be a permutation of the exact set it reorders — no missing,
     * extra, or duplicate ids — otherwise the resulting positions are ambiguous.
     *
     * @param list<int> $requested
     * @param list<int> $owned
     */
    private function assertExactSet(array $requested, array $owned, string $message): void
    {
        // $owned comes from map keys (unique), so once both are sorted a plain
        // equality rejects missing ids, extras, AND duplicates in $requested.
        $req = array_map('intval', $requested);
        sort($req);
        $own = array_map('intval', $owned);
        sort($own);

        if ($req !== $own) {
            throw new UnprocessableEntityHttpException($message);
        }
    }
}
