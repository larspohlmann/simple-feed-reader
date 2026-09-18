<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RestorePreviewJson;
use App\Http\RestoreResultJson;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\AccountRestorer;
use App\Service\Backup\BackupDownloadResponseFactory;
use App\Service\Backup\EntryPartRestorer;
use App\Service\Backup\RestorePreviewer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/account')]
final readonly class AccountBackupController
{
    public function __construct(
        private AccountBackupExporter $exporter,
        private BackupDownloadResponseFactory $downloads,
        private RestorePreviewer $previewer,
        private AccountRestorer $restorer,
        private EntryPartRestorer $entryPartRestorer,
    ) {
    }

    #[Route('/backup', name: 'api_account_backup', methods: ['GET'])]
    public function backup(#[CurrentUser] User $user, Request $request): StreamedResponse
    {
        return $this->downloads->stream(
            $user->getEmail(),
            $this->exporter->parts($user, $request->getSchemeAndHttpHost()),
        );
    }

    #[Route('/restore/preview', name: 'api_account_restore_preview', methods: ['POST'])]
    public function preview(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(RestorePreviewJson::from($this->previewer->preview($user, $request->getContent())));
    }

    #[Route('/restore/start', name: 'api_account_restore_start', methods: ['POST'])]
    public function start(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(RestoreResultJson::from(
            $this->restorer->start($user, $request->getContent(), $request->query->get('confirm')),
        ));
    }

    #[Route('/restore/entries', name: 'api_account_restore_entries', methods: ['POST'])]
    public function entries(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(RestoreResultJson::from(
            $this->entryPartRestorer->load($user, $request->getContent()),
        ));
    }
}
