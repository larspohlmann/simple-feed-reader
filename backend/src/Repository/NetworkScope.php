<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SocialNetwork;
use App\Exception\ValidationException;

final readonly class NetworkScope
{
    private function __construct(public SocialNetwork $network, public ?NetworkFeedKind $feedKind)
    {
    }

    public static function whole(SocialNetwork $network): self
    {
        return new self($network, null);
    }

    public static function profiles(SocialNetwork $network): self
    {
        return new self($network, NetworkFeedKind::Profile);
    }

    /**
     * @throws ValidationException for an unknown network or feed kind, or a feed kind without a network
     */
    public static function fromRequestValues(?string $network, ?string $feedKind): ?self
    {
        $socialNetwork = SocialNetwork::fromRequestValue($network);
        $kind = NetworkFeedKind::fromRequestValue($feedKind);
        if ($socialNetwork === null && $kind !== null) {
            throw new ValidationException(['feedKind' => ['A feedKind needs a network.']]);
        }

        if ($socialNetwork === null) {
            return null;
        }

        return $kind === null ? self::whole($socialNetwork) : self::profiles($socialNetwork);
    }

    public function toRunKey(): string
    {
        return $this->feedKind === null ? $this->network->value : $this->network->value . '-' . $this->feedKind->value;
    }
}
