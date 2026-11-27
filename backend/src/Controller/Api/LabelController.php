<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Label\LabelNameRequest;
use App\Entity\User;
use App\Http\LabelJson;
use App\Repository\LabelRepository;
use App\Service\Library\Label\LabelEditor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/labels')]
final readonly class LabelController
{
    public function __construct(
        private LabelRepository $labels,
        private LabelEditor $editor,
    ) {
    }

    #[Route('', name: 'api_labels_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(LabelJson::tallies($this->labels->tallyForUser($user->requireId())));
    }

    #[Route('', name: 'api_labels_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] LabelNameRequest $request): JsonResponse
    {
        return new JsonResponse(
            ['label' => LabelJson::one($this->editor->create($user, $request->toName()))],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'api_labels_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] LabelNameRequest $request,
    ): JsonResponse {
        $label = $this->labels->requireOneForUser($user->requireId(), $id);

        $this->editor->rename($label, $request->toName());

        return new JsonResponse(['label' => LabelJson::one($label)]);
    }

    #[Route('/{id}', name: 'api_labels_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->editor->delete($this->labels->requireOneForUser($user->requireId(), $id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
