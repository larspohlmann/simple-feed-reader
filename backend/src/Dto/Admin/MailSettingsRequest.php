<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\MailConnection;
use App\Enum\MailEncryption;
use App\Http\FullReplacePayload;
use App\Service\Crypto\Model\SecretChangeModel;
use App\Service\Mail\Settings\Model\MailSettingsUpdateModel;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every setting is required: its controller maps it with {@see FullReplacePayload::CONTEXT}, without which a
 * missing nullable setting reads as null. The password is an optional three-state intent: null keeps the stored
 * secret, a string replaces it, `removePassword` clears it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier, not a behavioural method.
 */
final readonly class MailSettingsRequest
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled,
        #[Assert\Length(max: 255)]
        public string $host,
        #[Assert\Range(min: 1, max: 65535)]
        public int $port,
        #[Assert\Length(max: 255)]
        public ?string $username,
        #[Assert\Choice(choices: [
            MailEncryption::None->value,
            MailEncryption::Starttls->value,
            MailEncryption::Tls->value,
        ])]
        public string $encryption,
        #[Assert\Length(max: 255)]
        #[Assert\Email]
        public string $fromAddress,
        #[Assert\Length(max: 255)]
        public string $fromName,
        #[Assert\Type('bool')]
        public bool $useProxy,
        #[Assert\Length(max: 512)]
        public ?string $password = null,
        #[Assert\Type('bool')]
        public bool $removePassword = false,
    ) {
    }

    public function toUpdate(): MailSettingsUpdateModel
    {
        return new MailSettingsUpdateModel(
            new MailConnection(
                $this->enabled,
                $this->host,
                $this->port,
                '' === $this->username ? null : $this->username,
                MailEncryption::from($this->encryption),
                $this->fromAddress,
                $this->fromName,
                $this->useProxy,
            ),
            $this->removePassword ? SecretChangeModel::remove() : SecretChangeModel::fromSubmitted($this->password),
        );
    }
}
