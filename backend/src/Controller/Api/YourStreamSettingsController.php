<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\YourStream\SaveYourStreamRequest;
use App\Entity\User;
use App\Http\YourStreamSettingsJson;
use App\Service\Curation\YourStream\YourStreamSettingsStateReader;
use App\Service\Curation\YourStream\YourStreamSettingsWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/me/your-stream')]
final readonly class YourStreamSettingsController
{
    public function __construct(
        private YourStreamSettingsStateReader $stateReader,
        private YourStreamSettingsWriter $writer,
    ) {
    }

    #[Route('', name: 'api_me_your_stream_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(YourStreamSettingsJson::state($this->stateReader->readState($user)));
    }

    #[Route('', name: 'api_me_your_stream_save', methods: ['PUT'])]
    public function save(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveYourStreamRequest $request,
    ): JsonResponse {
        $this->writer->save($user, $request->toTuning());

        return new JsonResponse(YourStreamSettingsJson::state($this->stateReader->readState($user)));
    }
}
