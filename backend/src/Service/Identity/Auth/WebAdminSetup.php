<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Service\Auth\Exception\InvalidSetupSecretException;
use App\Service\Auth\Exception\SetupUnavailableException;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The no-shell bootstrap: open only while an operator-set secret is configured and no admin exists, so it disables
 * itself with the first admin. The secret comes from the environment and is compared with hash_equals().
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
