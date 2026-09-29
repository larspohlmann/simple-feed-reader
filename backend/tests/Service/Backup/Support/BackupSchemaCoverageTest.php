<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Support;

use App\Entity\ActionToken;
use App\Entity\AiProviderSettings;
use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\GrafanaSettings;
use App\Entity\InstanceSetting;
use App\Entity\MailSendFailure;
use App\Entity\MailServerSettings;
use App\Entity\Preferences;
use App\Entity\ProxyServerSettings;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Entity\UserPasskey;
use App\Entity\WorkerHeartbeat;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\Support\BackupSchema;
use App\Tests\DbTestCase;
use App\Tests\Support\BackupFieldDeclarations;
use App\Tests\Support\FullyPopulatedAccount;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Couples the backup format to the ORM mapping: a column added to a backed-up table reaches neither the exporter nor
 * the line DTOs unless someone remembers, and a nullable one is lost silently (#556). Only mapped columns are seen;
 * an unmapped one holds no user data by construction.
 */
final class BackupSchemaCoverageTest extends DbTestCase
{
    /**
     * Every entity's surrogate key. A backup is identity-free by design — the
     * restore assigns new ids — so listing `id` nine times would be noise.
     */
    private const array UNIVERSALLY_SKIPPED = ['id'];

    /**
     * A restore writes into the account that is signed in, so no line names an
     * owner. It could not: an owner in the file would be an owner the user
     * picked.
     */
    private const string OWNER_IS_THE_RESTORING_ACCOUNT = 'The owning account. A restore writes into '
        . 'the signed-in account, so no line names an owner — an owner read from the file would be '
        . 'one the user chose for themselves.';

    private const string DIGEST_BACKUP_NOT_YET_WIRED = 'Email digest configuration, added ahead of '
        . 'the backup format\'s support for it. A later task of the digest plan (#636) carries it.';

    /** The line discriminator, written on every kind and naming no field. */
    private const array EVERY_LINE = ['kind'];

    /**
     * The keys no entity declaration claims, per kind, so the header's `createdAt` does not license a `createdAt` on
     * another line.
     */
    private const array FILE_SCAFFOLDING = [
        BackupSchema::KIND_HEADER => [
            'schemaVersion', 'createdAt', 'sourceUrl', 'sourceEmail', 'backupId', 'part', 'parts',
            'totals', 'totals.entries', 'totals.entryStates',
        ],
        BackupSchema::KIND_FOOTER => [
            'counts', 'counts.tag', 'counts.savedSearch', 'counts.feed', 'counts.subscription',
            'counts.entry', 'counts.entryState',
        ],
        // media[] and attachments[] hold value objects, not entities, so their subkeys are claimed here like the
        // footer's `counts`. A third such list earns its own NESTED_VALUE_OBJECTS map.
        BackupSchema::KIND_ENTRY => [
            'media.url', 'media.kind', 'media.width', 'media.height', 'media.previewImageUrl',
            'attachments.url', 'attachments.mimeType', 'attachments.durationInSeconds',
            'attachments.sizeInBytes', 'attachments.title',
        ],
    ];

    /**
     * Rows that belong to the instance, not to any one account. A field added
     * to one of these cannot produce the failure this test guards against,
     * because no per-account projection of them exists.
     */
    private const array INSTANCE_SCOPED = [
        InstanceSetting::class => 'Instance-wide configuration, identical for every account.',
        ProxyServerSettings::class => 'The instance\'s egress configuration (#490); an operator setting.',
        GrafanaSettings::class => 'The instance\'s Grafana/Loki wiring (#983); an operator setting.',
        MailServerSettings::class => 'The instance\'s outgoing-mail transport and identity (#834); an operator '
            . 'setting.',
        CatalogCategory::class => 'The shared discovery catalog, seeded per instance.',
        CatalogFeed::class => 'The shared discovery catalog, seeded per instance.',
        Category::class => 'A feed-declared category identity (#953); the same row for every '
            . 'account that sees it.',
        EntryCategory::class => 'A feed-declared entry↔category link (#953); identical for every '
            . 'account, since it names no account.',
        WorkerHeartbeat::class => 'Liveness telemetry for the refresh worker.',
        MailSendFailure::class => 'The instance\'s automated-mail failure log (#882); operational telemetry, '
            . 'cleared on the next successful send.',
    ];

    /**
     * Account-scoped and dropped in full, declared per entity rather than per field.
     * testTheExportWritesNoKeyThatNoDeclarationClaims() is what notices one of them starting to be exported.
     */
    private const array ACCOUNT_SCOPED_WHOLLY_DROPPED = [
        AiProviderSettings::class => 'Model endpoints and API keys. A backup is a file the '
            . 'user handles and mails around; credentials do not belong in one.',
        UserIdentity::class => 'OAuth identity links — see NEVER_BACKED_UP\'s reasoning.',
        ActionToken::class => 'Short-lived, single-use verification and reset tokens.',
        RecommendationSettings::class => 'The "For you" settings — guidance prompt, learned profile, '
            . 'caps and limits, and batch size (#935). Quick to set again after a restore, and not '
            . 'worth carrying in a file the user handles and mails around.',
        RecommendationRun::class => 'Run history. Regenerated by running the engine; large, and '
            . 'meaningless once its entries are gone.',
        RecommendationRunLog::class => 'Per-run diagnostics, tied to a run that is not restored.',
        RecommendationItem::class => 'Per-run picks, tied to a run that is not restored.',
        UserPasskey::class => 'Passkeys are bound to a device and to a relying-party id, so a '
            . 'credential restored into another account or onto another device could never '
            . 'authenticate. Exporting credential ids and public keys would widen the blast '
            . 'radius of a leaked backup file for no gain.',
        SavedSearchEntry::class => 'A derived saved-search membership row (#1116); rebuilt by the sweep, '
            . 'so a restore carries none.',
    ];

    /**
     * Doctrine class => [field or association => exported JSON key, or the list of keys one field is written as].
     * It lives in BackupFieldDeclarations because AccountRestorerTest proves the read half off the same list.
     */
    private const array BACKED_UP = BackupFieldDeclarations::BACKED_UP;

    /** Doctrine class => [field => why a backup leaves it behind]. */
    private const array NOT_BACKED_UP = [
        User::class => [
            'status' => 'The account\'s moderation state, decided by the sign-up flow and the '
                . 'admin. A restore runs against an account that is already active.',
            'createdAt' => 'When this instance opened the account. A restore fills an account that '
                . 'already exists rather than creating one, so the account\'s own age is not the '
                . 'file\'s to set.',
            'approvedAt' => 'An admin\'s decision on this instance, not the reader\'s data.',
            'lastLoginAt' => 'Sign-in telemetry, rewritten by the token issuer on the very next '
                . 'login. Restoring it would be stale before the user saw it.',
            'emailVerifiedAt' => 'Server-derived verification state (#636), like lastLoginAt: '
                . 'stamped by the verify-email and OIDC flows, never by the account holder. A '
                . 'restore runs against an account whose address this instance already verified '
                . 'or did not.',
            'accountLimits.trialEndsAt' => 'The instance\'s terms for this account, granted by the '
                . 'sign-up flow or by an admin.',
            'accountLimits.maxSubscriptions' => 'A per-account cap an admin grants. Carried in the '
                . 'file, it would be a quota the account holder writes for themselves.',
            'preferences' => 'Not a carried value: the pointer to the account\'s preferences row. '
                . 'The account line inlines that row\'s field instead of nesting it, so the '
                . 'pointer itself becomes no key.',
            'activeAiProviderSettings' => 'Points at an AiProviderSettings row, which '
                . 'ACCOUNT_SCOPED_WHOLLY_DROPPED leaves out in full.',
            'recommendationSettings' => 'Points at a RecommendationSettings row, which '
                . 'ACCOUNT_SCOPED_WHOLLY_DROPPED leaves out in full (#935).',
        ],
        Preferences::class => [
            'user' => self::OWNER_IS_THE_RESTORING_ACCOUNT,
            'digestEnabled' => self::DIGEST_BACKUP_NOT_YET_WIRED,
            'digestCadence' => self::DIGEST_BACKUP_NOT_YET_WIRED,
            'digestSendHour' => self::DIGEST_BACKUP_NOT_YET_WIRED,
            'digestWeekday' => self::DIGEST_BACKUP_NOT_YET_WIRED,
            'digestFormat' => self::DIGEST_BACKUP_NOT_YET_WIRED,
            'digestLastSentAt' => 'System-written, like lastLoginAt: the next send overwrites it, '
                . 'and a restored value would only delay that send by a stale watermark.',
            'passkeyOfferAnsweredAt' => 'Interface state, not account configuration: it records that the '
                . 'one-time enrolment offer was shown and answered. A restore into a fresh account should '
                . 'let that account see the offer.',
        ],
        Tag::class => [
            'user' => self::OWNER_IS_THE_RESTORING_ACCOUNT,
        ],
        SavedSearch::class => [
            'user' => self::OWNER_IS_THE_RESTORING_ACCOUNT,
            'includeInDigest' => self::DIGEST_BACKUP_NOT_YET_WIRED,
            'matchedUpToEntryId' => 'The membership sweep\'s high-water mark (#1116); a restored search '
                . 'starts at 0 and is re-swept.',
            'slug' => 'Derived: deterministically "<id>-<slug of term>" (SavedSearchSlug). A restored '
                . 'search gets a new id, so a carried-over slug would be stale; RestoreLoadPass '
                . 'regenerates it from the new id once the flush that assigns that id has run.',
        ],
        Feed::class => [
            'status' => 'Live fetch state, not the user\'s data. A restored feed starts clean.',
            'etag' => 'The HTTP validator for the last body this instance fetched. A restore has '
                . 'no such body, and a carried-over validator would make the first refresh take a '
                . '304 over an empty feed.',
            'lastModified' => 'The Last-Modified half of the same conditional-request pair as '
                . 'etag, dropped for the same reason.',
            'fetchSchedule.lastFetchedAt' => 'Fetch bookkeeping owned by FeedScheduler. A restored '
                . 'feed has never been fetched by this instance.',
            'fetchSchedule.lastSuccessfulFetchAt' => 'The same bookkeeping. Carried over, it would '
                . 'report a feed as healthy before this instance had reached it once.',
            'fetchSchedule.lastNewEntryAt' => 'The same bookkeeping — when this instance last saw '
                . 'new entries. A restored feed has seen none yet, so it starts unset.',
            'fetchSchedule.nextFetchAt' => 'The scheduler\'s due stamp. Left unset so the restored '
                . 'feed is due at once, which is what a reader wants after a restore.',
            'fetchSchedule.fetchIntervalMinutes' => 'A restored feed gets a virgin schedule and is '
                . 'refreshed immediately by the client — same rule as the OPML import.',
            'fetchSchedule.consecutiveFailures' => 'A failure streak against one instance\'s '
                . 'network. Restoring it would apply another host\'s backoff to a feed this one '
                . 'has never tried.',
            'fetchSchedule.lastErrorMessage' => 'The message behind that streak, and meaningless '
                . 'once the streak itself is not carried.',
        ],
        Subscription::class => [
            'user' => self::OWNER_IS_THE_RESTORING_ACCOUNT,
        ],
        SubscriptionTag::class => [
            'subscription' => 'The back-pointer to the parent line. Every subscription line nests '
                . 'its own tag references, so a join row never has to name the subscription it '
                . 'sits under.',
        ],
        Entry::class => [
            'location.urlHash' => 'Derived: sha256 of UrlNormalizer::normalize(url), which the file '
                . 'already carries. EntryBatchInserter recomputes it on restore, so it is '
                . 'never stale and never has to be dropped from the format later.',
            'image.checkedAt' => 'The time at which this instance judged the image (#1109). A '
                . 'restored image was not judged here, so the field stays empty. An empty field '
                . 'does not put the image into the check queue.',
            'image.verifyAttempts' => 'The queue marker and failure count of this instance\'s '
                . 'check. A restored image is not put into the queue. The instance shows it as '
                . 'the old instance did.',
        ],
        EntryState::class => [
            'user' => self::OWNER_IS_THE_RESTORING_ACCOUNT,
        ],
    ];

    /**
     * Security boundaries; moving a field out is never a fix for a red test. The file is user-supplied, so restorable
     * roles, identities, email or passwordChangedAt would grant ROLE_ADMIN, another's login or an undone revocation.
     * Why each: docs/backup.md#7-fields-a-restore-must-never-write.
     */
    private const array NEVER_BACKED_UP = [
        User::class => [
            'roles' => 'Privilege escalation: a hand-edited backup would grant ROLE_ADMIN.',
            'email' => 'Account identity; a restore must never move an account to another address.',
            'passwordHash' => 'Credential material.',
            'passwordChangedAt' => 'Token revocation. InvalidatePasswordChangeTokensListener rejects any '
                . 'JWT whose `iat` is older than this stamp, which is the whole mechanism by '
                . 'which a password reset kills tokens already in an attacker\'s hands. A '
                . 'restorable — or nullable — stamp would let the account holder roll it back '
                . 'from a file they wrote and bring a revoked bearer token back to life, which '
                . 'is the exact attack the column was added to close.',
        ],
    ];

    /**
     * Doctrine class => the `kind` of the line its fields are written on.
     *
     * Lives in `BackupFieldDeclarations::KIND_OF` for the same reason
     * `BACKED_UP` above does.
     */
    private const array KIND_OF = BackupFieldDeclarations::KIND_OF;

    /**
     * The headings the user-facing tables live under. A dropped thing is
     * looked for under its own heading and nowhere else.
     */
    private const string SECTION_INSTANCE_SCOPED = '### 6.1';
    private const string SECTION_WHOLLY_DROPPED = '### 6.2';
    private const string SECTION_DROPPED_FIELDS = '### 6.3';
    private const string SECTION_NEVER_WRITTEN = '## 7.';

    public function testEveryPersistedFieldOfACoveredEntityCarriesADecision(): void
    {
        foreach (array_keys(self::BACKED_UP) as $entityClass) {
            foreach ($this->persistedNames($entityClass) as $name) {
                self::assertTrue(
                    $this->isDeclared($entityClass, $name),
                    sprintf(
                        "%s::$%s is persisted but no backup decision exists for it.\n"
                        . 'Add it to BACKED_UP, NOT_BACKED_UP or NEVER_BACKED_UP in %s, '
                        . 'and add a row to docs/backup.md.',
                        $entityClass,
                        $name,
                        self::class,
                    ),
                );
            }
        }
    }

    /**
     * The per-field lists above only cover entities BACKED_UP names. A new
     * entity would slip past them entirely, so its scope is decided here.
     */
    public function testEveryMappedEntityCarriesAScopeDecision(): void
    {
        foreach ($this->mappedEntityClasses() as $entityClass) {
            self::assertTrue(
                $this->hasScopeDecision($entityClass),
                sprintf(
                    "%s is mapped but no backup scope decision exists for it.\n"
                    . 'Add it to BACKED_UP, INSTANCE_SCOPED or ACCOUNT_SCOPED_WHOLLY_DROPPED in %s.',
                    $entityClass,
                    self::class,
                ),
            );
        }
    }

    public function testEveryBackedUpFieldReachesTheExportersOutput(): void
    {
        $valuesByKind = $this->exportedValuesByKind('coverage@example.com');

        foreach (self::BACKED_UP as $entityClass => $fields) {
            $kind = self::KIND_OF[$entityClass];
            foreach ($fields as $field => $declared) {
                foreach ($this->exportedKeysFor($declared) as $exportedKey) {
                    $this->assertBackedUpFieldReachedTheExport(
                        $entityClass,
                        $field,
                        $exportedKey,
                        $kind,
                        $valuesByKind,
                    );
                }
            }
        }
    }

    /**
     * Checks the value, not just the key: a null would pass a key check, which is why FullyPopulatedAccount sets every
     * declared field. No field is excused; name a legitimate null here explicitly rather than weaken the assertion.
     *
     * @param array<string, array<string, list<mixed>>> $valuesByKind
     */
    private function assertBackedUpFieldReachedTheExport(
        string $entityClass,
        string $field,
        string $exportedKey,
        string $kind,
        array $valuesByKind,
    ): void {
        self::assertArrayHasKey(
            $exportedKey,
            $valuesByKind[$kind] ?? [],
            sprintf(
                '%s::$%s is declared BACKED_UP as "%s" on the %s line, but the exporter '
                . 'never writes that key.',
                $entityClass,
                $field,
                $exportedKey,
                $kind,
            ),
        );

        foreach ($valuesByKind[$kind][$exportedKey] as $value) {
            self::assertNotNull(
                $value,
                sprintf(
                    '%s::$%s is declared BACKED_UP as "%s" on the %s line, but a fully '
                    . 'populated account still exports it as null. Populate the field in '
                    . 'FullyPopulatedAccount — that class exists to make this exact failure '
                    . 'impossible.',
                    $entityClass,
                    $field,
                    $exportedKey,
                    $kind,
                ),
            );
        }
    }

    /**
     * The other direction: every key on every line must be claimed by a declaration here, which is what notices an
     * ACCOUNT_SCOPED_WHOLLY_DROPPED entity starting to be exported.
     */
    public function testTheExportWritesNoKeyThatNoDeclarationClaims(): void
    {
        self::assertSame(
            [],
            $this->undeclaredExportedKeysByKind('dropped@example.com'),
            sprintf(
                "The exporter writes keys that no declaration in %s claims.\n"
                . 'If a wholly dropped entity has started reaching the file it is now partly '
                . 'backed up: move it to BACKED_UP and NOT_BACKED_UP, field by field. '
                . 'Otherwise declare the new key against the field it comes from.',
                self::class,
            ),
        );
    }

    /**
     * Keeps docs/backup.md's tables from falling behind the reason strings. Searched per section, because a backticked
     * name repeats across the page; two rows of one name inside one section still alias each other.
     */
    public function testEveryDroppedThingAppearsInTheUserFacingDoc(): void
    {
        $documentation = (string) file_get_contents(__DIR__ . '/../../../../../docs/backup.md');

        $this->assertEveryEntityIsMentioned(self::INSTANCE_SCOPED, $documentation, self::SECTION_INSTANCE_SCOPED);
        $this->assertEveryEntityIsMentioned(
            self::ACCOUNT_SCOPED_WHOLLY_DROPPED,
            $documentation,
            self::SECTION_WHOLLY_DROPPED,
        );

        $this->assertEveryFieldHasARow(self::NOT_BACKED_UP, $documentation, self::SECTION_DROPPED_FIELDS);
        $this->assertEveryFieldHasARow(self::NEVER_BACKED_UP, $documentation, self::SECTION_NEVER_WRITTEN);
    }

    /**
     * @param array<class-string, string> $declarations
     */
    private function assertEveryEntityIsMentioned(
        array $declarations,
        string $documentation,
        string $sectionMarker,
    ): void {
        $section = $this->sectionOf($documentation, $sectionMarker);

        foreach (array_keys($declarations) as $entityClass) {
            $shortName = (new \ReflectionClass($entityClass))->getShortName();
            self::assertStringContainsString(
                $shortName,
                $section,
                sprintf(
                    'docs/backup.md section "%s" never mentions %s, which a backup drops.',
                    $sectionMarker,
                    $shortName,
                ),
            );
        }
    }

    /**
     * @param array<class-string, array<string, string>> $declarations
     */
    private function assertEveryFieldHasARow(array $declarations, string $documentation, string $sectionMarker): void
    {
        $section = $this->sectionOf($documentation, $sectionMarker);

        foreach ($declarations as $entityClass => $fields) {
            foreach (array_keys($fields) as $field) {
                self::assertStringContainsString(
                    '`' . $field . '`',
                    $section,
                    sprintf(
                        'docs/backup.md section "%s" has no row for %s::$%s, which a backup drops.',
                        $sectionMarker,
                        $entityClass,
                        $field,
                    ),
                );
            }
        }
    }

    /**
     * The lines under one heading, down to the next heading of the same or a
     * higher level. Empty is not a passing state: a renumbered page has to
     * fail here, rather than let every search run against nothing and pass.
     */
    private function sectionOf(string $documentation, string $marker): string
    {
        $level = \strlen(explode(' ', $marker, 2)[0]);
        $collected = [];
        $inside = false;

        foreach (explode("\n", $documentation) as $line) {
            if (!$inside) {
                $inside = str_starts_with($line, $marker);
                continue;
            }
            if ($this->startsANewSection($line, $level)) {
                break;
            }
            $collected[] = $line;
        }

        self::assertNotSame([], $collected, sprintf('docs/backup.md has no section "%s".', $marker));

        return implode("\n", $collected);
    }

    private function startsANewSection(string $line, int $level): bool
    {
        $hashes = strspn($line, '#');

        return 0 < $hashes && $hashes <= $level;
    }

    /**
     * @param string|list<string> $declared
     *
     * @return list<string>
     */
    private function exportedKeysFor(string|array $declared): array
    {
        return \is_string($declared) ? [$declared] : $declared;
    }

    /**
     * @param class-string $entityClass
     *
     * @return list<string> field names, embeddable parts and associations
     */
    private function persistedNames(string $entityClass): array
    {
        $metadata = $this->entityManager->getClassMetadata($entityClass);
        $names = array_merge($metadata->getFieldNames(), $metadata->getAssociationNames());

        return array_values(array_diff($names, self::UNIVERSALLY_SKIPPED));
    }

    private function isDeclared(string $entityClass, string $name): bool
    {
        foreach ([self::BACKED_UP, self::NOT_BACKED_UP, self::NEVER_BACKED_UP] as $declarations) {
            if (isset($declarations[$entityClass][$name])) {
                return true;
            }
        }

        return false;
    }

    private function hasScopeDecision(string $entityClass): bool
    {
        return isset(self::BACKED_UP[$entityClass])
            || isset(self::INSTANCE_SCOPED[$entityClass])
            || isset(self::ACCOUNT_SCOPED_WHOLLY_DROPPED[$entityClass]);
    }

    /** @return list<class-string> */
    private function mappedEntityClasses(): array
    {
        $classes = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isEmbeddedClass || $metadata->isMappedSuperclass) {
                continue;
            }
            $classes[] = $metadata->getName();
        }

        return $classes;
    }

    /**
     * Every exported key no declaration claims, by kind; empty passes. Per kind, since field names repeat across lines
     * and a global set would let any key excuse itself.
     *
     * @return array<string, list<string>>
     */
    private function undeclaredExportedKeysByKind(string $email): array
    {
        $undeclared = [];
        foreach ($this->exportedKeysByKind($email) as $kind => $keys) {
            $unclaimed = array_values(array_diff($keys, $this->claimedKeysOf($kind)));
            if ([] !== $unclaimed) {
                $undeclared[$kind] = $unclaimed;
            }
        }

        return $undeclared;
    }

    /** @return list<string> the keys one kind of line is allowed to carry */
    private function claimedKeysOf(string $kind): array
    {
        $keys = array_merge(self::EVERY_LINE, self::FILE_SCAFFOLDING[$kind] ?? []);
        foreach (self::BACKED_UP as $entityClass => $fields) {
            if (self::KIND_OF[$entityClass] !== $kind) {
                continue;
            }
            foreach ($fields as $declared) {
                $keys = array_merge($keys, $this->exportedKeysFor($declared));
            }
        }

        return $keys;
    }

    /**
     * The key half of exportedValuesByKind(), derived from it rather than walking the export twice.
     *
     * @return array<string, list<string>>
     */
    private function exportedKeysByKind(string $email): array
    {
        return array_map(array_keys(...), $this->exportedValuesByKind($email));
    }

    /**
     * Every value the exporter writes under each key, by kind, for one fully populated account: values, not only keys,
     * because a null would pass a key-only proof.
     *
     * @return array<string, array<string, list<mixed>>>
     */
    private function exportedValuesByKind(string $email): array
    {
        $user = $this->fullyPopulatedAccount()->create($email);

        $valuesByKind = [];
        foreach ($this->rawLinesOf($user) as $line) {
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            $kind = $decoded['kind'] ?? '';
            self::assertIsString($kind);
            foreach ($this->valuesOfOneLine($decoded) as $key => $values) {
                $valuesByKind[$kind][$key] = array_merge($valuesByKind[$kind][$key] ?? [], $values);
            }
        }

        return $valuesByKind;
    }

    /**
     * Every NDJSON line the exporter writes for $user, across every gzip
     * part — the parts themselves are a download-time split, not a schema
     * distinction the coverage proof needs to keep separate.
     *
     * @return iterable<string>
     */
    private function rawLinesOf(User $user): iterable
    {
        foreach ($this->exporter()->parts($user, 'https://coverage.example') as $part) {
            foreach (explode("\n", (string) gzdecode($part->gzipBytes)) as $line) {
                if ('' !== $line) {
                    yield $line;
                }
            }
        }
    }

    /**
     * A line's values by key, plus a dotted key per nested key (`counts.feed`, `tags.position`), one level deep as the
     * format is. Each maps to a list, since a nested key repeats once per element.
     *
     * @param array<string, mixed> $line
     *
     * @return array<string, list<mixed>>
     */
    private function valuesOfOneLine(array $line): array
    {
        $values = [];
        foreach ($line as $key => $value) {
            $values[$key][] = $value;
            foreach ($this->nestedObjects($value) as $nested) {
                foreach ($nested as $nestedKey => $nestedValue) {
                    $values[$key . '.' . $nestedKey][] = $nestedValue;
                }
            }
        }

        return $values;
    }

    /**
     * The objects held under one key: the footer's `counts` is a single object, a subscription line's tag references
     * are a list of several.
     *
     * @return list<array<array-key, mixed>>
     */
    private function nestedObjects(mixed $value): array
    {
        if (!is_array($value) || [] === $value) {
            return [];
        }

        if (!array_is_list($value)) {
            return [$value];
        }

        $objects = [];
        foreach ($value as $element) {
            if (is_array($element)) {
                $objects[] = $element;
            }
        }

        return $objects;
    }

    private function fullyPopulatedAccount(): FullyPopulatedAccount
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new FullyPopulatedAccount($this->entityManager, $hasher);
    }

    private function exporter(): AccountBackupExporter
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);

        return $exporter;
    }
}
