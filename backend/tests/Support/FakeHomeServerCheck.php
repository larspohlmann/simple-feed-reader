<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\HomeServerCheck\HomeServerCheckInterface;

/** A post's home server that confirms a deletion only when told to; it records each address asked about. */
final class FakeHomeServerCheck implements HomeServerCheckInterface
{
    /** @var list<string> */
    public array $askedAddresses = [];

    private bool $confirms = false;

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function confirmsDeletions(): void
    {
        $this->confirms = true;
    }

    public function confirmsDeleted(string $address): bool
    {
        $this->askedAddresses[] = $address;

        return $this->confirms;
    }
}
