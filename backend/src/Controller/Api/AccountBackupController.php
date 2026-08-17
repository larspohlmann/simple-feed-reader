<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\BackupDownloadResponseFactory;
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
    ) {
    }

    #[Route('/backup', name: 'api_account_backup', methods: ['GET'])]
    public function backup(#[CurrentUser] User $user, Request $request): StreamedResponse
    {
        return $this->downloads->stream($this->exporter->lines($user, $request->getSchemeAndHttpHost()));
    }
}
