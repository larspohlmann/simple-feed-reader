<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Digest\DigestImageEmbedder;

use App\Service\Outbound\Mail\Digest\Model\DigestImageSetModel;
use App\Service\Outbound\Mail\Digest\Model\DigestPageModel;

interface DigestImageEmbedderInterface
{
    public function embed(DigestPageModel $page): DigestImageSetModel;
}
