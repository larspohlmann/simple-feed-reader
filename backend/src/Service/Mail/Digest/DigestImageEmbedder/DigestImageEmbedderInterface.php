<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestImageEmbedder;

use App\Service\Mail\Digest\DigestImageSet;
use App\Service\Mail\Digest\DigestPage;

interface DigestImageEmbedderInterface
{
    public function embed(DigestPage $page): DigestImageSet;
}
