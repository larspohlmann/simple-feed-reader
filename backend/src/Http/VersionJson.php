<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Version\VersionReport;

final class VersionJson
{
    /** @return array<string, mixed> */
    public static function of(VersionReport $report): array
    {
        $latest = $report->latest;

        return [
            'version' => $report->running->version,
            'commit' => $report->running->commit,
            'builtAt' => $report->running->builtAt,
            'latest' => null === $latest ? null : [
                'version' => $latest->version,
                'notesUrl' => $latest->notesUrl,
            ],
            'updateAvailable' => $report->updateAvailable,
        ];
    }
}
