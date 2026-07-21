<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Exception\ValidationException;
use App\Repository\UserRepository;
use App\Service\Mail\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The approval queue. Access is enforced by ROLE_ADMIN on ^/api/admin/ in
 * security.yaml — the Angular route guard is UX only.
 */
#[Route('/api/admin/users')]
final class AdminUserController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AccountMailer $mailer,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'api_admin_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $statusFilter = $request->query->get('status');
        $statuses = null;

        if (\is_string($statusFilter) && '' !== $statusFilter) {
            $status = UserStatus::tryFrom($statusFilter);
            if (null === $status) {
                throw new ValidationException(['status' => ['Unknown account status.']]);
            }
            $statuses = [$status];
        }

        return new JsonResponse([
            'users' => array_map(
                // Hand-built, like GET /api/me: a column added later must not
                // reach an admin's browser just because it exists.
                static fn (User $user): array => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'status' => $user->getStatus()->value,
                    'roles' => $user->getRoles(),
                    'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'approvedAt' => $user->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                ],
                $this->users->findForAdminList($statuses),
            ),
        ]);
    }

    #[Route('/{id}/approve', name: 'api_admin_users_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(int $id): JsonResponse
    {
        $user = $this->requireUser($id);

        $user->setStatus(UserStatus::Active);
        $user->setApprovedAt($this->clock->now());
        $this->em->flush();

        $this->mailer->sendApproved($user);

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    #[Route('/{id}/reject', name: 'api_admin_users_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->requireNotSelf($id, $admin);

        $user->setStatus(UserStatus::Rejected);
        $this->em->flush();

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    #[Route('/{id}/suspend', name: 'api_admin_users_suspend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function suspend(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->requireNotSelf($id, $admin);

        $user->setStatus(UserStatus::Suspended);
        $this->em->flush();

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    private function requireUser(int $id): User
    {
        return $this->users->find($id) ?? throw new NotFoundHttpException('User not found.');
    }

    /**
     * Guards against an admin removing their own access. The admin UI is the
     * only way back in, so this is not recoverable without database access.
     *
     * approve() deliberately has no such guard: approving yourself cannot lock
     * anyone out, and the only cost is a redundant "you're in" mail.
     */
    private function requireNotSelf(int $id, User $admin): User
    {
        $user = $this->requireUser($id);

        if ($user->getId() === $admin->getId()) {
            throw new ValidationException(['id' => ['You cannot change your own account status.']]);
        }

        return $user;
    }
}
