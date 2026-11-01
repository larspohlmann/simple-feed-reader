<?php

declare(strict_types=1);

namespace App\Service\Url\EntryIdentityUrl;

interface EntryIdentityUrlInterface
{
    public function forGuid(string $guid): ?string;
}
