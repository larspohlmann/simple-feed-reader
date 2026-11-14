<?php

declare(strict_types=1);

namespace App\Service\Version\Model;

/**
 * A prerelease ranks BELOW its own final release, so an instance on `v1.4.2-dev.3` is ahead of every earlier
 * release. Only `vMAJOR.MINOR.PATCH[-dev.N]` parses, and an unparseable version is never part of an upgrade.
 */
final readonly class SemanticVersionModel
{
    private function __construct(
        private int $major,
        private int $minor,
        private int $patch,
        /** null for a final release; the N of a `-dev.N` prerelease otherwise. */
        private ?int $prereleaseNumber,
    ) {
    }

    public static function tryParse(string $raw): ?self
    {
        if (1 !== preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:-dev\.(\d+))?$/', $raw, $parts)) {
            return null;
        }

        return new self(
            (int) $parts[1],
            (int) $parts[2],
            (int) $parts[3],
            isset($parts[4]) ? (int) $parts[4] : null,
        );
    }

    /**
     * True when $candidate is a valid version that ranks strictly above
     * $current. Either side failing to parse yields false: an update is only
     * ever claimed between two versions we can actually order.
     */
    public static function isUpgrade(string $current, string $candidate): bool
    {
        $currentVersion = self::tryParse($current);
        $candidateVersion = self::tryParse($candidate);
        if (null === $currentVersion || null === $candidateVersion) {
            return false;
        }

        return $candidateVersion->isNewerThan($currentVersion);
    }

    private function isNewerThan(self $other): bool
    {
        if ($this->major !== $other->major) {
            return $this->major > $other->major;
        }
        if ($this->minor !== $other->minor) {
            return $this->minor > $other->minor;
        }
        if ($this->patch !== $other->patch) {
            return $this->patch > $other->patch;
        }

        return $this->prereleaseRank() > $other->prereleaseRank();
    }

    private function prereleaseRank(): int
    {
        return $this->prereleaseNumber ?? \PHP_INT_MAX;
    }
}
