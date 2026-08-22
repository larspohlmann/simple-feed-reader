<?php

declare(strict_types=1);

namespace App\Service\Version;

/**
 * A release tag ranked by semver, with one rule that matters here: a prerelease
 * sits BELOW its own final release. The deploy tags this project runs
 * (`v1.4.2-dev.3`) are prereleases of `v1.4.2`, so an instance on a dev tag is
 * ahead of every earlier release and only lapped once its final release ships.
 *
 * Deliberately narrow: it parses `vMAJOR.MINOR.PATCH` with an optional
 * `-dev.N` prerelease and nothing else. A development build (`dev`) or any
 * other shape does not parse, and an unparseable version can never be part of
 * an upgrade — which is exactly the silent-absence behaviour the badge needs.
 */
final readonly class SemanticVersion
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

    /**
     * A final release outranks any prerelease of the same MAJOR.MINOR.PATCH.
     * Ranks compare so that release (PHP_INT_MAX) beats every `-dev.N`, and a
     * higher dev number beats a lower one.
     */
    private function prereleaseRank(): int
    {
        return $this->prereleaseNumber ?? \PHP_INT_MAX;
    }
}
