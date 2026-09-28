<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Model;

use App\Entity\MailConnection;
use App\Service\Crypto\Model\SecretChangeModel;

final readonly class MailSettingsUpdateModel
{
    public function __construct(
        public MailConnection $connection,
        public SecretChangeModel $password,
    ) {
    }
}
