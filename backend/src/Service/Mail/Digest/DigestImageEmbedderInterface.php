<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

interface DigestImageEmbedderInterface
{
    public function embed(DigestPage $page): DigestImageSet;
}
