<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestMailer;

use App\Entity\DigestSettings;
use App\Service\Mail\Digest\Model\DigestModel;

interface DigestMailerInterface
{
    public function send(DigestSettings $settings, DigestModel $model): void;
}
