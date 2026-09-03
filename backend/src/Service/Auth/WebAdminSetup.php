<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Exception\InvalidSetupSecretException;
use App\Exception\SetupUnavailableException;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The no-shell bootstrap path. Available only while an operator-set secret is
 * configured AND no administrator exists yet; it self-disables the instant an
 * admin exists, so the endpoint has no standing attack surface.
 *
 * The secret is sourced from the environment — the one config channel every
 * cheap Docker host offers — and compared with hash_equals in constant time.
 * On success the caller gets a JWT and lands logged-in.
 */
final readonly class WebAdminSetup
{
    public function __construct(
        private UserRepository $users,
        private BootstrapAdminProvisioner $provisioner,
        private JWTTokenManagerInterface $jwtManager,
        #[Autowire('%env(ADMIN_SETUP_SECRET)%')]
        private string $configuredSecret,
    ) {
    }

    public function createFirstAdmin(string $email, string $password, string $secret): string
    {
        if ('' === $this->configuredSecret || $this->users->hasAnyAdmin()) {
            throw new SetupUnavailableException();
        }

        if (!hash_equals($this->configuredSecret, $secret)) {
            throw new InvalidSetupSecretException();
        }

        return $this->jwtManager->create($this->provisioner->provision($email, $password));
    }
}
