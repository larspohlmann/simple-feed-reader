<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Tag\CreateTagRequest;
use App\Dto\Tag\UpdateTagRequest;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\TagNameTakenException;
use App\Http\TagJson;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/tags')]
final class TagController
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly SubscriptionRepository $subscriptions,
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
        $this->em->persist($tag);
        $this->em->flush();

        return new JsonResponse(['tag' => TagJson::one($tag)], Response::HTTP_CREATED);
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
}
