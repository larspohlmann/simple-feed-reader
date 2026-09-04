<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Service\Crypto\Exception\SecretUnreadableException;

/**
 * Turns a stored configuration into the connection a completion call needs.
 *
 * Separate from AiProviderConfigurator, which owns the provider relationship
 * — creating, verifying and removing a configuration. Reading one back as a
 * ready-to-use connection is a different job, and the configurator is the
 * class this codebase has to keep from growing: it opens the sealed key here
 * rather than duplicating the cipher call.
 */
final readonly class ProviderConnectionFactory
{
    public function __construct(private AiProviderConfigurator $configurator)
    {
    }

    /**
     * @throws SecretUnreadableException
     */
    public function forSettings(AiProviderSettings $settings): ProviderConnection
    {
        return new ProviderConnection($this->configurator->credentials($settings), $this->timeoutsFor($settings));
    }

    /**
     * The profile alone, for a caller that must size something against a
     * call's duration without making one — the run advancer's lock TTL. No key
     * is opened, so this cannot fail.
     */
    public function timeoutsFor(AiProviderSettings $settings): ProviderTimeouts
    {
        return $settings->isSlowModel() ? ProviderTimeouts::forSlowModel() : ProviderTimeouts::standard();
    }
}
