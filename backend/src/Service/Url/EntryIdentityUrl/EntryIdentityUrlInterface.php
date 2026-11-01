<?php

declare(strict_types=1);

namespace App\Service\Url\EntryIdentityUrl;

/** A platform whose guid names its post more stably than the link a feed happens to print. */
interface EntryIdentityUrlInterface
{
    public function forGuid(string $guid): ?string;
}
