<?php

declare(strict_types=1);

namespace App\Enum;

enum SubscriptionSet
{
    case All;

    /** A private feed needs an account the file does not hold; saved and scraped feeds are this reader's own. */
    case OpmlExport;

    /** Everything but the private feeds; saved feeds are restored as saved feeds (#1666). */
    case Backup;

    /** @return list<string> the formats left out; a private feed following one person stays in either way */
    public function excludedFormats(): array
    {
        return match ($this) {
            self::All => [],
            self::OpmlExport => SourceFormat::NOT_IN_OPML,
            self::Backup => SourceFormat::SOCIAL,
        };
    }
}
