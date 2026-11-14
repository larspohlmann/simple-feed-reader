<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Digest\DigestMailer;

use App\Entity\DigestSettings;
use App\Service\Outbound\Mail\Digest\Model\DigestModel;

interface DigestMailerInterface
{
    public function send(DigestSettings $settings, DigestModel $model): void;
}
