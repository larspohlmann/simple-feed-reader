<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Image\ImageProxy\ImageProxyInterface;
use App\Service\Image\Model\ProxiedImageModel;

final class StubImageProxy implements ImageProxyInterface
{
    /** @var list<string> */
    public array $fetched = [];

    public function __construct(private readonly ProxiedImageModel|\Throwable $answer)
    {
    }

    public function fetch(string $url): ProxiedImageModel
    {
        $this->fetched[] = $url;
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
