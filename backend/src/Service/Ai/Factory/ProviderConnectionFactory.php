<?php

declare(strict_types=1);

namespace App\Service\Ai\Factory;

use App\Entity\AiProviderSettings;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Model\ProviderConnectionModel;
use App\Service\Ai\Model\ProviderTimeoutsModel;

/**
 * Reads a stored configuration back as the connection a completion call needs, opening the key through
 * AiProviderConfigurator::credentials() rather than a second cipher call.
 */
final readonly class ProviderConnectionFactory
{
    public function __construct(private AiProviderConfigurator $configurator)
    {
    }

    /**
     * @throws AiKeyUnreadableException
     */
    public function forSettings(AiProviderSettings $settings): ProviderConnectionModel
    {
        return new ProviderConnectionModel($this->configurator->credentials($settings), $this->timeoutsFor($settings));
    }

    /**
     * The profile alone, for a caller that must size something against a
     * call's duration without making one — the run advancer's lock TTL. No key
     * is opened, so this cannot fail.
     */
    public function timeoutsFor(AiProviderSettings $settings): ProviderTimeoutsModel
    {
        return $settings->isSlowModel() ? ProviderTimeoutsModel::forSlowModel() : ProviderTimeoutsModel::standard();
    }
}
