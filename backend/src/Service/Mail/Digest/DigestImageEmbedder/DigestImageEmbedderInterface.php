<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestImageEmbedder;

use App\Service\Mail\Digest\Model\DigestImageSetModel;
use App\Service\Mail\Digest\Model\DigestPageModel;

interface DigestImageEmbedderInterface
{
    public function embed(DigestPageModel $page): DigestImageSetModel;
}
