<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\HomeServerCheck\HomeServerCheckInterface;

/** A post's home server that confirms a deletion only when told to; it records each address asked about. */
final class FakeHomeServerCheck implements HomeServerCheckInterface
{
    /** @var list<string> */
    public array $askedAddresses = [];

    private bool $confirms = false;

    private ?SocialNetworkUnreachableException $unreachable = null;

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function confirmsDeletions(): void
    {
        $this->confirms = true;
    }

    public function failsWith(SocialNetworkUnreachableException $unreachable): void
    {
        $this->unreachable = $unreachable;
    }

    public function confirmsDeleted(string $address): bool
    {
        $this->askedAddresses[] = $address;
        if (null !== $this->unreachable) {
            throw $this->unreachable;
        }

        return $this->confirms;
    }
}
