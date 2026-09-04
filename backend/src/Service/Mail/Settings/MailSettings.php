<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Dto\Admin\MailSettingsRequest;
use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Http\Admin\MailSettingsJson;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the instance-wide mail row, defaulting to "not configured"
 * when no row exists. The rest of the app depends on this, never on the entity
 * directly, so "no row yet", the sealing, and the DB-or-env resolution all live
 * in one place.
 */
readonly class MailSettings
{
    private const int HINT_LENGTH = 4;

    public function __construct(
        private MailServerSettingsRepository $repository,
        private EntityManagerInterface $em,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
    ) {
    }

    /**
     * @return array{
     *     enabled: bool, host: string, port: int, username: string|null,
     *     encryption: string, fromAddress: string, fromName: string,
     *     hasPassword: bool, passwordHint: string,
     *     hasSavedConfig: bool, envFallbackConfigured: bool,
     * }
     */
    public function view(): array
    {
        return MailSettingsJson::from($this->repository->findSingleton(), $this->fallback->context());
    }

    public function resetToEnvironment(): void
    {
        $settings = $this->repository->findSingleton();
        if (null !== $settings) {
            $this->em->remove($settings);
            $this->em->flush();
        }
    }

    public function update(MailSettingsRequest $request): void
    {
        $existing = $this->repository->findSingleton();
        $connection = $this->connectionFrom($request);
        $this->guardAgainstIncompleteAuthenticatedRow($request, $connection, $existing);

        $settings = $existing;
        if (null === $settings) {
            $settings = new MailServerSettings();
            $this->em->persist($settings);
        }

        if (null === $request->password) {
            $settings->applyWithoutPassword($connection);
        } else {
            $settings->apply(
                $connection,
                $this->cipher->seal($request->password),
                mb_substr($request->password, -self::HINT_LENGTH),
            );
        }

        $this->em->flush();
    }

    /** The saved SMTP transport regardless of the enable switch — the tester and
     *  the dynamic transport resolve this. Null when nothing usable is saved. */
    public function configuredTransport(): ?ResolvedMailTransport
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ResolvedMailTransport(
            $settings->getHost(),
            $settings->getPort(),
            $settings->getUsername(),
            $settings->hasPassword() ? $this->cipher->open($settings->getSealedPassword()) : null,
            $settings->getEncryption(),
        );
    }

    public function activeTransportDsnFallback(): string
    {
        return $this->fallback->transportDsn();
    }

    public function identity(): MailIdentity
    {
        $settings = $this->repository->findSingleton();

        if (null !== $settings && '' !== $settings->getFromAddress()) {
            return new MailIdentity($settings->getFromAddress(), $settings->getFromName());
        }

        return $this->fallback->identity();
    }

    public function isSendingEnabled(): bool
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings ? $settings->isEnabled() : $this->fallback->context()->isReal;
    }

    /** Refuses to persist an enabled, authenticated row with no password on
     *  record: it would resolve to a live, host-bearing transport that fails
     *  every send, silently overriding a working env fallback. */
    private function guardAgainstIncompleteAuthenticatedRow(
        MailSettingsRequest $request,
        MailConnection $connection,
        ?MailServerSettings $existing,
    ): void {
        $willHavePassword = null !== $request->password || ($existing?->hasPassword() ?? false);
        $isAuthenticatedTransport = $connection->enabled
            && '' !== $connection->host
            && null !== $connection->username;

        if ($isAuthenticatedTransport && !$willHavePassword) {
            throw new IncompleteMailConfigurationException();
        }
    }

    private function connectionFrom(MailSettingsRequest $request): MailConnection
    {
        return new MailConnection(
            $request->enabled,
            $request->host,
            $request->port,
            '' === $request->username ? null : $request->username,
            MailEncryption::from($request->encryption),
            $request->fromAddress,
            $request->fromName,
        );
    }
}
