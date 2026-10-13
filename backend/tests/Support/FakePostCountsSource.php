<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\PostCountsSource\PostCountsSourceInterface;

/** A public Mastodon counts source that answers the counts it was given, or fails as told; it records each ask. */
final class FakePostCountsSource implements PostCountsSourceInterface
{
    /** @var list<string> */
    public array $askedAddresses = [];

    private ?\Throwable $failure = null;

    public function __construct(private readonly SocialCountsModel $counts)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function recovers(): void
    {
        $this->failure = null;
    }

    public function countsOf(string $address): SocialCountsModel
    {
        $this->askedAddresses[] = $address;
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->counts;
    }
}
