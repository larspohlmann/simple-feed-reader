<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Tag\CreateTagRequest;
use App\Dto\Tag\ReorderTagsRequest;
use App\Dto\Tag\TagFeedOrderRequest;
use App\Dto\Tag\UpdateTagRequest;
use App\Entity\Tag;
use App\Entity\User;
use App\Http\TagJson;
use App\Repository\TagRepository;
use App\Service\Tag\TagEditor;
use App\Service\Tag\TagOrdering;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/tags')]
final readonly class TagController
{
    public function __construct(
        private TagRepository $tags,
        private TagEditor $editor,
        private TagOrdering $ordering,
    ) {
    }

    #[Route('', name: 'api_tags_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->tags->findForUser($user->requireId());

        return new JsonResponse([
            'tags' => array_map(static fn (Tag $t) => TagJson::one($t), $rows),
        ]);
    }

    #[Route('', name: 'api_tags_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] CreateTagRequest $request): JsonResponse
    {
        return new JsonResponse(
            ['tag' => TagJson::one($this->editor->create($user, $request))],
            Response::HTTP_CREATED,
        );
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
        return new JsonResponse([
            'tags' => array_map(
                static fn (Tag $tag): array => TagJson::one($tag),
                $this->ordering->reorder($user, $request),
            ),
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
        $tag = $this->tags->getOneForUser($user->requireId(), $id);

        $this->ordering->orderFeeds($tag, $request);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'api_tags_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateTagRequest $request,
    ): JsonResponse {
        $tag = $this->tags->getOneForUser($user->requireId(), $id);

        $this->editor->update($tag, $request);

        return new JsonResponse(['tag' => TagJson::one($tag)]);
    }

    #[Route('/{id}', name: 'api_tags_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $tag = $this->tags->getOneForUser($user->requireId(), $id);

        $this->editor->delete($tag);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
