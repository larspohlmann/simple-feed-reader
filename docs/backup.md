# Account backup and restore

This page tells you what an account backup holds, what it does not hold, and
what a restore does to the account you are signed in to.

Read section 3 before you restore. A restore replaces the account. It does not
merge.

**Contents:**
[1 What a backup is](#1-what-a-backup-is) ·
[2 How to make a backup](#2-how-to-make-a-backup) ·
[3 What a restore does](#3-what-a-restore-does) ·
[4 When a restore fails](#4-when-a-restore-fails) ·
[5 What a backup carries](#5-what-a-backup-carries) ·
[6 What a backup does not carry](#6-what-a-backup-does-not-carry) ·
[7 Fields a restore must never write](#7-fields-a-restore-must-never-write) ·
[8 For developers: the format and its guards](#8-for-developers-the-format-and-its-guards)

---

## 1. What a backup is

A backup is one file. The file holds the reading data of one account: the tags,
the saved searches, the feeds, the subscriptions, the articles, the read marks
and the settings.

The file is gzip-compressed NDJSON. Each line is one JSON object. Each object
has a `kind` field that tells you what the line is: `header`, `account`, `tag`,
`savedSearch`, `feed`, `subscription`, `entry`, `entryState` or `footer`.

A backup holds no database identifiers. A restore makes new identifiers.
Therefore you can restore a backup into a different account, and into a
different instance.

A backup holds no credentials. Section 6 and section 7 tell you what stays
behind, and why.

## 2. How to make a backup

1. Open **Settings**.
2. Go to **Account backup**.
3. Click **Download backup**.

The browser downloads a file with a name such as
`simplefeedreader-0_7_0-ada-at-example-20260823.json.gz`. The name holds the
application version, the account address and the export date.

A client can also request the file directly:

```
GET /api/account/backup
```

The request needs the account's bearer token. The response is a stream, so a
large account does not have to fit into memory.

Keep the file safe. The file holds everything you read and everything you
marked.

## 3. What a restore does

A restore is destructive. The restore does these steps, in this order:

1. It reads the whole file and counts the lines.
2. It refuses the file if the file is not a valid backup.
3. It refuses the file if the file is too large for the account.
4. It deletes the account's tags, saved searches, subscriptions, read marks,
   "For you" runs and "For you" settings, and it sets the scrape fallback
   preference back to off.
5. It loads the file into the account.

To start a restore:

1. Open **Settings**.
2. Go to **Account backup**.
3. Choose the backup file. The application shows what it will delete and what
   the file holds.
4. Download the OPML safety net if you want a copy of the current
   subscriptions.
5. Type `REPLACE` in the confirmation field.
6. Click **Replace this account**.

The confirmation word is mandatory. The application starts no restore without
it.

The account address of the backup does not have to agree with the address of
the account you are signed in to. You can restore your own file into a new
account on another instance.

An old backup file loses articles. The first refresh after a restore prunes the
articles that are older than the retention window.

## 4. When a restore fails

There are three failure groups. The difference between them is important,
because the three groups leave the account in different states.

**The web server refuses the upload. Nothing is deleted.** A file larger than
the server's own upload limit never reaches the application. The server
answers with a raw HTTP 413 status. The response has no `problem+json` body,
because the application never runs. The app shows its own message instead, so
you still get a plain reason. This failure costs the account nothing: the
account is unchanged. Ask an operator to raise the server's upload limit, or
export a smaller account.

**The restore refuses the file. Nothing is deleted.** The application answers
with `invalid_backup` or with `backup_does_not_fit`. The first answer means the
bytes are not an acceptable backup: not gzip, not NDJSON, a missing line, a line
in the wrong order, a format version this instance cannot read, a repeated tag
name, or a reference to a row the same file never declares. The second answer
means the file holds more subscriptions than the account allows, or more lines
of one kind than the format permits. Both answers come from the first pass, and
the first pass runs before the deletion. **A refused file costs the account
nothing.** The account is unchanged. You can correct the file and try again.

**The restore fails after the deletion. The account is empty.** The application
answers with `backup_load_failed`. Two causes give this answer. Usually the
database refuses a value that the format accepts — a title that is longer than
the column, a number that is too wide for its type, a duplicate key. More
rarely, the file points at a row that the same file never declares. The first
pass must already refuse such a file, so this second cause is a backstop. It
stays because a partial load that keeps quiet is much worse than a load that
stops and reports. The message of this answer says that the account is now
empty, because that is the fact you must know. Correct the file, or export it
again, then run the restore again with the same file. The deletion is
repeatable, so a second attempt starts from the same clean state.

The restore is not one transaction. This is deliberate. If the load stops in the
middle, the account holds a part of the file. The remedy is the same: run the
restore again with the same file.

## 5. What a backup carries

| Line | What the line holds |
|---|---|
| `header` | The format version, the export date, the address of the instance the file came from, and the account address the file came from. |
| `account` | Your language (`locale`), the scrape fallback setting (`scrapeFallbackEnabled`), and all "For you" settings (`recommendationSettings`). |
| `tag` | Each tag: `name`, `color`, `icon` and `position`. |
| `savedSearch` | Each saved search: `term`, `wholeWord` and `position`. |
| `feed` | Each feed you subscribe to: `url`, `siteUrl`, `title`, `description`, `faviconUrl`, `imageUrl` and `sourceFormat`. |
| `subscription` | Each subscription: `customTitle`, `position`, `markedReadUntil`, `createdAt` (the date the subscription started), and the tags on the subscription with their order. |
| `entry` | Each article, with the address of the feed it came from: `guid`, `url`, `title`, `author`, `summary`, `contentHtml`, the image (`imageUrl`, `imageWidth`, `imageHeight`), `publishedAt`, `createdAt` (the date this instance first saw the article) and `effectiveDate`. |
| `entryState` | Each article mark: `isHidden`, `isViewed`, `isFavorite`, `isKept`, `hiddenAt` and `viewedAt`. Each mark names its article by feed and by article identifier. |
| `footer` | The number of lines of each kind. The restore uses these numbers to show you what the file holds. |

The "For you" settings in the `account` line are complete: the guidance prompt,
the learned profile text, all limits and caps, the lookback window, the context
window, the batch count, the automatic interval, and the two display switches.

## 6. What a backup does not carry

### 6.1 Data that belongs to the instance, not to your account

These rows are the same for every account on the instance. An operator sets
them, or the instance makes them. A backup carries none of them, and a restore
writes none of them.

| Data | Why the file leaves it out |
|---|---|
| `InstanceSetting` | Instance-wide configuration. It is the same for every account on the instance. |
| `ProxyServerSettings` | The network egress configuration of the instance. An operator sets it. |
| `CatalogCategory` | The shared discovery catalog. Each instance holds its own copy. |
| `CatalogFeed` | The shared discovery catalog. Each instance holds its own copy. |
| `WorkerHeartbeat` | The liveness record of the refresh worker. It is a machine record, not your data. |

### 6.2 Account data that the file drops in full

These rows belong to your account. A backup drops each of them completely.

| Data | Why the file leaves it out |
|---|---|
| `AiProviderSettings` | Your AI connections: the endpoint and the API key. A backup is a file you handle, store and send. A key must not travel in such a file. Your AI connections stay in the account, and a restore does not touch them. |
| `UserIdentity` | Your links to Google and Apple sign-in. A restore writes what the file says, and the file comes from you. See section 7. |
| `ActionToken` | Short-lived tokens for address verification and password reset. Each token lives for minutes and works once. |
| `RecommendationRun` | The history of your "For you" runs. Run the engine again to get new results. The history is large, and it points at articles the restore has replaced. |
| `RecommendationRunLog` | The diagnostic log of one run. It has no meaning without the run, and the run is not restored. |
| `RecommendationItem` | The picks of one run. They have no meaning without the run, and the run is not restored. |

Your "For you" **settings** are carried. Only the **results** are dropped.

### 6.3 Fields the file drops

**On the account.**

| Field | Why the file leaves it out |
|---|---|
| `status` | The state of the account: waiting for approval, active, or blocked. The sign-up flow and the administrator decide it. You restore into an account that is already active. |
| `createdAt` | The date this instance opened the account. A restore fills an account that already exists. It does not make one. Therefore the age of the account is not the file's to set. |
| `approvedAt` | The date an administrator approved the account on this instance. It is a decision of the instance, not your reading data. |
| `lastLoginAt` | The date of your last sign-in. The next sign-in writes it again, so a restored value is old before you see it. |
| `emailVerifiedAt` | The date this instance proved you control your address (#636). Written by the verify-email and sign-in-with-provider flows, never by you. A restore runs against an account this instance already verified or did not. |
| `accountLimits.trialEndsAt` | The end of the trial period this instance gave the account. The instance decides its own terms. |
| `accountLimits.maxSubscriptions` | The subscription limit an administrator gave the account. If the file carried it, you could write your own limit. |
| `preferences` | Not a value, but the pointer from the account to its preferences row. The `account` line writes the preference itself, so the pointer becomes no key in the file. |
| `activeAiProviderSettings` | The pointer to the AI connection in use. It points at data that section 6.2 drops in full. |
| `digestEnabled`, `digestCadence`, `digestSendHour`, `digestWeekday` | The email digest settings (#636). Added ahead of the backup format's support for them; a later task carries them. |
| `digestLastSentAt` | The date the digest last sent. The next send writes it again, so a restored value would only delay that send. |

**On each row you own.** The preferences, the "For you" settings, each tag, each
subscription and each article mark all hold one pointer to their owner.

| Field | Why the file leaves it out |
|---|---|
| `user` | The pointer to the account that owns the row. A restore writes into the account you are signed in to, so no line names an owner. It could not: an owner read from the file would be an owner you chose for yourself. |
| `includeInDigest` | Whether a saved search feeds the email digest (#636). Added ahead of the backup format's support for it; a later task carries it. |

**On a feed.**

| Field | Why the file leaves it out |
|---|---|
| `status` | The live fetch state of the feed on this instance. A restored feed starts clean. |
| `etag` | The HTTP validator for the last feed body this instance fetched. The new instance has no such body. If it kept the old validator, the first refresh would get "not modified" for a feed it has never read. |
| `lastModified` | The second half of the same pair of conditional-request values as `etag`. It is left out for the same reason. |
| `fetchSchedule.lastFetchedAt` | Fetch bookkeeping of the instance. A restored feed has never been fetched by the new instance. |
| `fetchSchedule.lastSuccessfulFetchAt` | The same bookkeeping. If it were carried, the new instance would report the feed as healthy before it had reached the feed once. |
| `fetchSchedule.nextFetchAt` | The date the scheduler must fetch the feed again. It stays empty, so a restored feed is due immediately. That is what you want after a restore. |
| `fetchSchedule.fetchIntervalMinutes` | The interval between two fetches. A restored feed gets a new schedule and is refreshed immediately, as an OPML import is. |
| `fetchSchedule.consecutiveFailures` | The count of failures in sequence against one network. If it were carried, the new instance would apply the backoff of another host to a feed it has never tried. |
| `fetchSchedule.lastErrorMessage` | The message behind that count of failures. It has no meaning when the count itself is not carried. |

**On a tag reference in a subscription.**

| Field | Why the file leaves it out |
|---|---|
| `subscription` | The pointer from a tag reference back to its subscription. Each `subscription` line holds its own tag references, so a reference never has to name the subscription above it. |

**On an article.**

| Field | Why the file leaves it out |
|---|---|
| `urlHash` | A value the application calculates from the article address, which the file already carries. The restore calculates it again for each article. Therefore it is never old, and the format never has to drop it later. |

## 7. Fields a restore must never write

The fields in the table below are a security boundary. They are not a product
decision. No backup carries them, and no restore writes them.

A restore writes what the file says. The file comes from you. You can open the
file, edit one line and upload it again. Therefore each of these fields must
stay outside the format.

| Field | What a restorable field would permit |
|---|---|
| `roles` | Any account holder could edit one line, write the administrator role into the file, restore the file, and become an administrator. |
| `email` | An account holder could move the account to an address that they do not control, or to the address of another person. |
| `passwordHash` | Credential material. It must never travel in a file that a user handles. |
| `passwordChangedAt` | This value is the token revocation control. The application refuses each sign-in token that is older than this date, and that is how a password reset kills the tokens an attacker already holds. If a user could write this date from a file, the user could undo a revocation and make a dead token live again. |

The same rule applies to your Google and Apple sign-in links (`UserIdentity` in
section 6.2). If a restore wrote them, a user could attach the identity of
another person to their own account.

Your sign-in, your password, your roles and your AI connections stay in the
account through a restore. The restore does not delete them, and it does not
change them.

## 8. For developers: the format and its guards

Four tests hold this format together. Read them before you change a backed-up
entity.

- `backend/tests/Service/Backup/BackupSchemaCoverageTest.php` reads the ORM
  mapping and demands a decision for each persisted field of each backed-up
  entity. A new column on a backed-up table makes this test red. The test also
  asserts that this page names each dropped entity and each dropped field, so
  the dropped-field tables above (6.2, 6.3, 7) cannot fall behind the code. It
  also proves that a fully populated account never exports a backed-up field
  as null, so a field cannot pass this test merely because its test fixture
  left it empty.
- `backend/tests/Service/Backup/AccountRestorerTest.php::testEveryBackedUpFieldSurvivesTheRestoreRoundTrip`
  guards the other direction. `BackupSchemaCoverageTest` proves that the
  exporter writes each backed-up field. It does not prove that the restore
  reads that field back. A field can pass the write-direction test and still
  get lost: the exporter writes it, but no Line DTO reads it back on restore.
  This test closes that gap. Both tests read the same field list, from
  `backend/tests/Support/BackupFieldDeclarations.php`. A field added to that
  list gets both tests for free.
- `backend/tests/Service/Backup/AccountBackupExporterTest.php` guards what the
  exporter writes.
- `backend/tests/Service/Backup/GoldenBackupRestoreTest.php` restores two frozen
  files on each run. They guard what the reader still accepts.

Section 5 is different: no test couples it to the code. A field that stays
`BACKED_UP` never has to change section 5, so nothing forces a red test when a
new carried field is missing its row there. Keep it accurate by hand when you
add a row under "Where a new decision goes" below.

**The rule for an additive field.** When you add a field to the format, add
nothing to the golden corpus in `backend/tests/Fixtures/backup/`. The file
`oldest-supported.ndjson` does not hold the new field already, and that absence
is the test: it proves that an older backup still restores. Add a third file
only when support for something is dropped for the first time.

**Where a new decision goes.** Put a new field in one of these lists in
`BackupSchemaCoverageTest`:

| List | Meaning |
|---|---|
| `BACKED_UP` | The file carries the field. Add the row to section 5 of this page. |
| `NOT_BACKED_UP` | The file drops the field. Add a row to section 6.3 with a reason a reader understands. |
| `NEVER_BACKED_UP` | A security boundary. Add a row to section 7. Moving a field out of this list is never the answer to a red test. |
| `ACCOUNT_SCOPED_WHOLLY_DROPPED` | A whole entity that belongs to the account and is dropped. Add a row to section 6.2. |
| `INSTANCE_SCOPED` | A whole entity that belongs to the instance. Add a row to section 6.1. |
