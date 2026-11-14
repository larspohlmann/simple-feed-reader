<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\EntryIdentityUrl;

interface EntryIdentityUrlInterface
{
    public function forGuid(string $guid): ?string;
}
