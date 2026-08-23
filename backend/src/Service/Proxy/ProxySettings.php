<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Dto\Admin\ProxySettingsRequest;
use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Http\Admin\ProxySettingsJson;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Fetch\ProxyConfig;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the instance-wide proxy row, defaulting to "not configured"
 * when no row exists. The rest of the app depends on this, never on the entity
 * or repository directly, so "no row yet" and the sealing both live in one place.
 */
readonly class ProxySettings
{
    private const int HINT_LENGTH = 4;

    public function __construct(
        private ProxyServerSettingsRepository $repository,
        private EntityManagerInterface $em,
        private ProxyPasswordCipher $cipher,
    ) {
    }

    /**
     * @return array{
     *     enabled: bool,
     *     directFallback: bool,
     *     type: string,
     *     host: string,
     *     port: int,
     *     username: string|null,
     *     remoteDns: bool,
     *     hasPassword: bool,
     *     passwordHint: string,
     * }
     */
    public function view(): array
    {
        return ProxySettingsJson::from($this->repository->findSingleton());
    }

    public function update(ProxySettingsRequest $request): void
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings) {
            $settings = new ProxyServerSettings();
            $this->em->persist($settings);
        }

        $connection = $this->connectionFrom($request);

        if (null === $request->password) {
            $settings->applyWithoutPassword($connection);
        } else {
            $settings->apply(
                $connection,
                $this->cipher->seal($request->password),
                // Cut on characters, not bytes: substr() would split a
                // multibyte password mid-codepoint and store a hint that no
                // JSON response can encode.
                mb_substr($request->password, -self::HINT_LENGTH),
            );
        }

        $this->em->flush();
    }

    /** The stored connection regardless of the enable switch — the tester probes this. */
    public function configuredProxy(): ?ProxyConfig
    {
        return $this->proxyFrom($this->repository->findSingleton());
    }

    /** The connection only when it is turned on — the fetch paths resolve this. */
    public function egressProxy(): ?ProxyConfig
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings && $settings->isEnabled() ? $this->proxyFrom($settings) : null;
    }

    /**
     * Built from a row already in hand, so a caller that had to load the row to
     * read the enable switch does not pay for a second lookup and a second
     * password decryption to reach the same connection.
     */
    private function proxyFrom(?ProxyServerSettings $settings): ?ProxyConfig
    {
        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ProxyConfig(
            $settings->getType(),
            $settings->getHost(),
            $settings->getPort(),
            $settings->getUsername(),
            $settings->hasPassword() ? $this->cipher->open($settings->getSealedPassword()) : null,
            $settings->isDirectFallback(),
            $settings->isRemoteDns(),
        );
    }

    private function connectionFrom(ProxySettingsRequest $request): ProxyConnection
    {
        return new ProxyConnection(
            $request->enabled,
            $request->directFallback,
            ProxyType::from($request->type),
            $request->host,
            $request->port,
            '' === $request->username ? null : $request->username,
            $request->remoteDns,
        );
    }
}
