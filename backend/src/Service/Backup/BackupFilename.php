<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The backup download's filename: app slug, release version, account and
 * export date, joined by "-". Both human- and machine-readable is a real
 * constraint here, not decoration — the name has to survive being pasted into
 * a shell, a support ticket, or a bug report, so every field is reduced to
 * [a-z0-9_-] and nothing else. Sanitisation lives in exactly one place
 * (self::sanitised()) so a stray "+" tag in an address, a pre-release suffix
 * like "-dev.3", or an accented character all collapse the same way.
 */
final readonly class BackupFilename
{
    private const string APP_SLUG = 'simplefeedreader';
    private const string SUFFIX = '.json.gz';

    public function __construct(
        private string $accountEmail,
        private string $releaseVersion,
        private \DateTimeImmutable $exportedAt,
    ) {
    }

    public function value(): string
    {
        $fields = [
            self::APP_SLUG,
            $this->normalisedVersion(),
            $this->normalisedAccount(),
            $this->exportedAt->format('Ymd'),
        ];

        return implode('-', $fields) . self::SUFFIX;
    }

    /** "v0.6.2" -> "0_6_2"; "0.7.0-dev.3" -> "0_7_0-dev_3"; "dev" -> "dev". */
    private function normalisedVersion(): string
    {
        $lowered = strtolower($this->releaseVersion);
        $unprefixed = str_starts_with($lowered, 'v') ? substr($lowered, 1) : $lowered;

        return self::sanitised(str_replace('.', '_', $unprefixed));
    }

    /** "ada.lovelace+tag@fastmail.com" -> "ada-lovelace-tag-at-fastmail". */
    private function normalisedAccount(): string
    {
        $parts = explode('@', $this->accountEmail, 2);
        $localPart = $parts[0];
        $domain = $parts[1] ?? '';
        $firstDomainLabel = explode('.', $domain)[0];

        return sprintf(
            '%s-at-%s',
            self::sanitised(str_replace('.', '-', $localPart)),
            self::sanitised($firstDomainLabel),
        );
    }

    /**
     * Lowercases, replaces every run of characters outside [a-z0-9_-] with a
     * single "-", collapses any run of repeated "-" or "_" left behind by
     * that substitution, and trims stray separators from both ends. The one
     * normalisation rule the whole filename obeys.
     */
    private static function sanitised(string $value): string
    {
        $lowered = strtolower($value);
        $withoutForeignCharacters = preg_replace('/[^a-z0-9_-]+/', '-', $lowered) ?? '';
        $withoutRepeatedHyphens = preg_replace('/-{2,}/', '-', $withoutForeignCharacters) ?? '';
        $withoutRepeatedUnderscores = preg_replace('/_{2,}/', '_', $withoutRepeatedHyphens) ?? '';

        return trim($withoutRepeatedUnderscores, '-_');
    }
}
