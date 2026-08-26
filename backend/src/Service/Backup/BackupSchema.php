<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The backup file's constants: the schema version this build writes and
 * reads, and the `kind` discriminator values a line's JSON carries.
 */
final class BackupSchema
{
    public const int VERSION = 2;

    public const string KIND_HEADER = 'header';
    public const string KIND_ACCOUNT = 'account';
    public const string KIND_TAG = 'tag';
    public const string KIND_SAVED_SEARCH = 'savedSearch';
    public const string KIND_FEED = 'feed';
    public const string KIND_SUBSCRIPTION = 'subscription';
    public const string KIND_ENTRY = 'entry';
    public const string KIND_ENTRY_STATE = 'entryState';
    public const string KIND_FOOTER = 'footer';

    private function __construct()
    {
        // Constants only; never instantiated.
    }
}
