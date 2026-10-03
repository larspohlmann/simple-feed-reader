<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\ProfileRun;

/** One profile run; an account that never ran one reads status `none` with every other field empty. */
final class ProfileRunJson
{
    /** @return array<string, mixed> */
    public static function current(?ProfileRun $profileRun): array
    {
        return null === $profileRun ? self::none() : self::run($profileRun);
    }

    /** @return array<string, mixed> */
    public static function run(ProfileRun $profileRun): array
    {
        return [
            'status' => $profileRun->getStatus()->value,
            'id' => $profileRun->getId(),
            'trigger' => $profileRun->getTrigger()->value,
            'outcome' => $profileRun->getOutcome()?->value,
            'error' => $profileRun->getError(),
            'createdAt' => $profileRun->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completedAt' => $profileRun->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'providerHost' => $profileRun->getProviderHost(),
            'model' => $profileRun->getModel(),
            'attempts' => $profileRun->getAttempts(),
            'maxAttempts' => ProfileRun::MAX_ATTEMPTS,
            'transportFailures' => $profileRun->getTransportFailures(),
            'maxTransportFailures' => ProfileRun::MAX_TRANSPORT_FAILURES,
            'streamedChars' => $profileRun->getStreamedChars(),
        ];
    }

    /** @return array<string, mixed> */
    private static function none(): array
    {
        return [
            'status' => 'none',
            'id' => null,
            'trigger' => null,
            'outcome' => null,
            'error' => null,
            'createdAt' => null,
            'completedAt' => null,
            'providerHost' => null,
            'model' => null,
            'attempts' => 0,
            'maxAttempts' => ProfileRun::MAX_ATTEMPTS,
            'transportFailures' => 0,
            'maxTransportFailures' => ProfileRun::MAX_TRANSPORT_FAILURES,
            'streamedChars' => 0,
        ];
    }

    private function __construct()
    {
    }
}
