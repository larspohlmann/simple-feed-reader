<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\GrafanaConnection;
use App\Http\FullReplacePayload;
use App\Service\Crypto\Model\SecretChangeModel;
use App\Service\Grafana\Model\GrafanaSettingsUpdateModel;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every setting is required: its controller maps it with {@see FullReplacePayload::CONTEXT}, without which a
 * missing nullable setting reads as null. A null URL falls back to the env default. The token is an optional
 * three-state intent: null keeps it, a string replaces it, `removeToken` clears it.
 */
final readonly class GrafanaSettingsRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $lokiPushUrl,
        #[Assert\Length(max: 255)]
        public ?string $lokiUsername,
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $grafanaUrl,
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $pyroscopePushUrl,
        #[Assert\Type('bool')]
        public bool $profilingEnabled,
        #[Assert\Length(max: 512)]
        public ?string $token = null,
        #[Assert\Type('bool')]
        public bool $removeToken = false,
    ) {
    }

    public function toUpdate(): GrafanaSettingsUpdateModel
    {
        return new GrafanaSettingsUpdateModel(
            new GrafanaConnection(
                self::blankToNull($this->lokiPushUrl),
                self::blankToNull($this->lokiUsername),
                self::blankToNull($this->grafanaUrl),
                self::blankToNull($this->pyroscopePushUrl),
                $this->profilingEnabled,
            ),
            $this->removeToken
                ? SecretChangeModel::remove()
                : SecretChangeModel::fromSubmitted(self::blankToNull($this->token)),
        );
    }

    private static function blankToNull(?string $value): ?string
    {
        return '' === $value ? null : $value;
    }
}
