<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Account\AccountDeleter;
use App\Service\Admin\UserStatusChanger;
use App\Service\Auth\PasswordResetter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/admin/users')]
final readonly class AdminUserModerationController
{
    public function __construct(
        private UserRepository $users,
        private UserStatusChanger $statusChanger,
        private PasswordResetter $passwordResetter,
        private AccountDeleter $accountDeleter,
    ) {
    }

    /** @throws TransportExceptionInterface */
    #[Route('/{id}/approve', name: 'api_admin_users_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(int $id): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->statusChanger->approve($user);

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    #[Route('/{id}/reject', name: 'api_admin_users_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->statusChanger->reject($user, $admin);

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    #[Route('/{id}/suspend', name: 'api_admin_users_suspend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function suspend(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->statusChanger->suspend($user, $admin);

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    /**
     * No SelfActionGuard: unlike reject/suspend, generating oneself a new
     * password cannot lock anybody out — the admin ends up with a working
     * account either way.
     */
    #[Route(
        '/{id}/reset-password',
        name: 'api_admin_users_reset_password',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function resetPassword(int $id): JsonResponse
    {
        $user = $this->users->getById($id);

        // Returned once, in the response body only, for the admin to relay out of
        // band. The supported recovery path when the instance sends no mail.
        return new JsonResponse(['password' => $this->passwordResetter->generateAndSet($user)]);
    }

    #[Route('/{id}', name: 'api_admin_users_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $this->accountDeleter->deleteAsAdmin($this->users->getById($id), $admin);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
