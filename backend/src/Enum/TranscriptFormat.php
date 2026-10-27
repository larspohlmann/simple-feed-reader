<?php

declare(strict_types=1);

namespace App\Enum;

enum TranscriptFormat: string
{
    case Vtt = 'vtt';
    case Json = 'json';
    case Srt = 'srt';
    case Html = 'html';

    public static function fromMimeType(string $mimeType): ?self
    {
        $essence = strtolower(trim(explode(';', $mimeType)[0]));

        return match ($essence) {
            'text/vtt' => self::Vtt,
            'application/json' => self::Json,
            'application/x-subrip', 'application/srt' => self::Srt,
            'text/html' => self::Html,
            default => null,
        };
    }

    public function preference(): int
    {
        return match ($this) {
            self::Vtt => 0,
            self::Json => 1,
            self::Srt => 2,
            self::Html => 3,
        };
    }
}
