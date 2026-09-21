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

A backup is one zip archive. The archive holds the reading data of one
account: the tags, the saved searches, the feeds, the subscriptions, the
articles, the read marks and the settings.

The archive holds one or more parts. Each part is a gzip-compressed NDJSON
document. Each line of a part is one JSON object. Each object has a `kind`
field that tells you what the line is: `header`, `account`, `tag`,
`savedSearch`, `feed`, `subscription`, `entry`, `entryState` or `footer`. One
part carries the account, the tags, the saved searches, the feeds and the
subscriptions. The other parts carry the articles and the read marks, split
across as many parts as the account needs.

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
`simplefeedreader-0_7_0-ada-at-example-20260823.zip`. The name holds the
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

A restore is destructive. It runs in your browser, part by part, and sends one
request per part. The browser does these steps, in this order:

1. It opens the archive and checks it, without sending anything to the server:
   the foundation part is present, the entry parts are named in order with no
   gap, every part passes its checksum, and every part's header names the same
   backup and matches its own place in the sequence. The browser refuses the
   archive on the first check it fails.
2. It sends the foundation part to the server for a preview. The server
   refuses the part if the part is not a valid backup, or if the part is too
   large for the account. The application shows what a restore would delete
   and what the file holds.
3. You type `REPLACE` in the confirmation field and click **Replace this
   account**. The confirmation word is mandatory. The application starts no
   restore without it.
4. The browser sends the foundation part again, this time to start the
   restore. The server deletes the account's tags, saved searches,
   subscriptions, read marks, "For you" runs and "For you" settings, sets the
   scrape fallback preference back to off, and loads the foundation part.
5. The browser sends each entry part in turn, one request at a time, and adds
   up what each request loaded. Section 4 tells you what happens when one of
   these requests fails.

To start a restore:

1. Open **Settings**.
2. Go to **Account backup**.
3. Choose the backup file.
4. Download the OPML safety net if you want a copy of the current
   subscriptions.
5. Type `REPLACE` in the confirmation field.
6. Click **Replace this account**.

The account address of the backup does not have to agree with the address of
the account you are signed in to. You can restore your own file into a new
account on another instance.

An old backup file loses articles. The first refresh after a restore prunes the
articles that are older than the retention window.

A file with the old, single-file format (`.json.gz`) is not readable. Version 2
backups cannot be restored. Export the account again to get a file this
version can read.

## 4. When a restore fails

There are three failure groups. The difference between them is important,
because the three groups leave the account in different states.

**The browser refuses the archive. Nothing is sent, nothing is deleted.** The
check in step 1 of section 3 fails: a part is missing, the entry parts are not
in order, a part fails its checksum, or a part's header does not match its
place in the archive. The application shows a plain reason and sends no
request. Export the account again, or check that the file is a whole,
unmodified download.

**The server refuses the preview or the start. Nothing is deleted.** This is
the foundation part, checked before the account is touched. The server answers
with `invalid_backup` or with `backup_does_not_fit`. The first answer means
the part is not an acceptable backup: not gzip, not NDJSON, a missing line, a
line in the wrong order, a format version this instance cannot read, a
repeated tag name, or a reference to a row the same part never declares. The
second answer means the file holds more subscriptions than the account
allows, more lines of one kind than the format permits, or the file's own
totals already exceed the account's article ceiling. **A refused foundation
part costs the account nothing.** Correct the export and try again.

**A request fails after the account was replaced. The account holds part of
the file, or none of it yet.** Once the server accepts the start request, the
account's old data is gone before that same request returns.

If the start request itself fails, even after it has deleted the old data —
for example with `backup_load_failed`, because it could not load the
foundation it had just deleted for — the run stops with no **Continue**
button, because no entry part has loaded yet. Choose the file again and run
the whole restore again from the start.

If a later entry part fails, the browser first retries it, up to three times
with a growing wait, but only when the failure looks temporary: the network
dropped, or the server answered "too many requests" or "took too long". A
part the server refuses outright — `invalid_backup` (for example, a reference
to a feed the account does not subscribe to), `backup_does_not_fit` (the
account's article count would go past its ceiling), or `backup_load_failed`
(the database rejected a value, or the file refers to a row it never
declares) — is not retried, because sending the same bytes again would fail
the same way. Either way, once an entry part cannot be loaded, the run stops
and the application shows a **Continue** button. Continue resumes at the part
that failed, using the same open file, and does not resend the parts that
already loaded: entry parts are additive, so a resend of an already-loaded
part adds nothing a second time. If you reload the page before pressing
Continue, the application forgets where the run stopped; choose the file
again and run the whole restore again from the start. Loading the same parts
again is safe for the same reason: an entry or a read mark the account
already holds is never duplicated.

The restore is not one transaction. This is deliberate. If the load stops
partway through the entry parts, the account holds the parts that loaded
before the failure. The remedy is Continue, or a full re-run with the same
file.

## 5. What a backup carries

| Line | What the line holds |
|---|---|
| `header` | The format version, the export date, the address of the instance the file came from, and the account address the file came from. See the header field table below. |
| `account` | Your language (`locale`), the scrape fallback setting (`scrapeFallbackEnabled`), and the magazine style (`magazineStyle`). |
| `tag` | Each tag: `name`, `color`, `icon` and `position`. |
| `savedSearch` | Each saved search: `term`, `wholeWord`, `phrase` and `position`. |
| `feed` | Each feed you subscribe to: `url`, `siteUrl`, `title`, `description`, `faviconUrl`, `imageUrl` and `sourceFormat`. |
| `subscription` | Each subscription: `customTitle`, `position`, `markedReadUntil`, `createdAt` (the date the subscription started), and the tags on the subscription with their order. |
| `entry` | Each article, with the address of the feed it came from: `guid`, `url`, `title`, `author`, `summary`, `contentHtml`, the image (`imageUrl`, `imageWidth`, `imageHeight`), `publishedAt`, `createdAt` (the date this instance first saw the article) and `effectiveDate`. |
| `entryState` | Each article mark: `isHidden`, `isViewed`, `isFavorite`, `isKept`, `hiddenAt` and `viewedAt`. Each mark names its article by feed and by article identifier. |
| `footer` | The number of lines of each kind, counting the lines of that part only. The restore uses these numbers to show you what the part holds. |

Every part carries its own `header` line. The header holds these fields in
addition to the format version and the export date:

| Field | Where it appears | Meaning |
|---|---|---|
| `backupId` | Every part | A random identifier. Every part of the same export carries the same one. It is how the browser confirms that every part belongs to this archive before the restore begins; the server does not read it. |
| `part` | Every part | `0` for the foundation part, `1` and up for each entry part, in the order the parts load. |
| `parts` | The foundation part only | The total number of parts in the archive, foundation included. |
| `totals` | The foundation part only | The number of articles and the number of article marks across the whole export. |

## 6. What a backup does not carry

### 6.1 Data that belongs to the instance, not to your account

These rows are the same for every account on the instance. An operator sets
them, or the instance makes them. A backup carries none of them, and a restore
writes none of them.

| Data | Why the file leaves it out |
|---|---|
| `InstanceSetting` | Instance-wide configuration. It is the same for every account on the instance. |
| `ProxyServerSettings` | The network egress configuration of the instance. An operator sets it. |
| `MailServerSettings` | The instance's outgoing-mail transport and identity. An operator sets it. |
| `GrafanaSettings` | The instance's Grafana/Loki wiring. An operator sets it. |
| `CatalogCategory` | The shared discovery catalog. Each instance holds its own copy. |
| `CatalogFeed` | The shared discovery catalog. Each instance holds its own copy. |
| `Category` | A category a feed declared on an article. The same category is the same row for every account that sees it. |
| `EntryCategory` | The link between an article and a category it was published under. It names no account. |
| `WorkerHeartbeat` | The liveness record of the refresh worker. It is a machine record, not your data. |
| `MailSendFailure` | The record of automated e-mails the instance failed to send. It is a machine record, not your data. |

### 6.2 Account data that the file drops in full

These rows belong to your account. A backup drops each of them completely.

| Data | Why the file leaves it out |
|---|---|
| `AiProviderSettings` | Your AI connections: the endpoint and the API key. A backup is a file you handle, store and send. A key must not travel in such a file. Your AI connections stay in the account, and a restore does not touch them. |
| `UserIdentity` | Your links to Google and Apple sign-in. A restore writes what the file says, and the file comes from you. See section 7. |
| `ActionToken` | Short-lived tokens for address verification and password reset. Each token lives for minutes and works once. |
| `RecommendationSettings` | Your "For you" settings: the guidance prompt, the learned profile, the caps and limits, and the batch size. They are quick to set again after a restore, and not worth carrying in a file you handle and send. |
| `RecommendationRun` | The history of your "For you" runs. Run the engine again to get new results. The history is large, and it points at articles the restore has replaced. |
| `RecommendationRunLog` | The diagnostic log of one run. It has no meaning without the run, and the run is not restored. |
| `RecommendationItem` | The picks of one run. They have no meaning without the run, and the run is not restored. |
| `UserPasskey` | Your passkeys. A passkey is tied to one device and to this instance's identity. A credential restored onto another account, or onto another device, could never sign you in. Carrying credential ids and keys in the file would only make a stolen backup more dangerous. |

Your "For you" **settings** and **results** are both dropped. A restore leaves
the settings at their defaults; set them again once, and run the engine.

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
| `recommendationSettings` | The pointer to your "For you" settings row. It points at data that section 6.2 drops in full. |
| `digestEnabled`, `digestCadence`, `digestSendHour`, `digestWeekday`, `digestFormat` | The email digest settings (#636, #726). Added ahead of the backup format's support for them; a later task carries them. |
| `digestLastSentAt` | The date the digest last sent. The next send writes it again, so a restored value would only delay that send. |
| `passkeyOfferAnsweredAt` | Whether you have already been offered a passkey (#624). This is the state of the app on this device, not a setting of your account. A restore into a fresh account should let that account see the offer. |

**On each row you own.** The preferences, each tag, each subscription and each
article mark all hold one pointer to their owner.

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
| `fetchSchedule.lastNewEntryAt` | The same bookkeeping: when the instance last saw new entries. A restored feed has seen none yet, so it stays empty. |
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
| `image.checkedAt` | The time at which this instance judged the image (#1109). A restored image was not judged here, so the field stays empty. An empty field does not put the image into the check queue. |
| `image.verifyAttempts` | The queue marker and failure count of this instance's check. A restored image is not put into the queue. The instance shows it as the old instance did. |

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

### 8.1 The container

The download is a zip archive, built with the **store** method: no member is
compressed by the zip layer itself. Each member is already a gzip-compressed
NDJSON document, so the client posts a member's bytes to the restore
endpoints exactly as they sit in the archive.

Members:

- `000-foundation.ndjson.gz` — the account, the tags, the saved searches, the
  feeds and the subscriptions. Written **last** by the exporter, because the
  exporter streams the entry parts first and only then knows the total part
  count and the entry totals that the foundation's header carries (section 5).
- `001-entries.ndjson.gz` … `NNN-entries.ndjson.gz` — the articles and their
  marks, in export order. Written first. The zip's own member order carries
  no meaning; a reader finds the foundation by name, not by position.

Two budgets bound a part, on the write side:

- An entry part closes once it holds 2,000 `entry` lines, or 8 MiB of
  inflated line bytes, whichever comes first.
- `POST /api/account/restore/entries` (section 8.2) enforces its own,
  looser ceiling on the read side, independent of how the part was written:
  at most 5,000 `entry` lines and 64 MiB of inflated bytes per part
  (`BackupReader::MAX_ENTRIES_PER_PART`, `BackupReader::MAX_INFLATED_BYTES`).
  A part that fails this ceiling is refused before any row is written.

### 8.2 The endpoints

All four routes sit under `/api/account`, take the account's bearer token, and
answer JSON, with `application/problem+json` on failure.

| Route | Body | What it does |
|---|---|---|
| `GET /backup` | — | Streams the zip archive. |
| `POST /restore/preview` | The foundation part | Validates the part and checks it fits the account. Deletes nothing. Returns the file's provenance and what a restore would load and delete. |
| `POST /restore/start?confirm=REPLACE` | The foundation part | Validates and fit-checks the part again, wipes the account, and loads the foundation. |
| `POST /restore/entries` | One entry part | Loads the part additively. No confirmation phrase. |

There is no restore session on the server and no finish call: each request
carries everything the server needs, and the server keeps nothing between
requests. The version 2 single-file format's `POST /restore` route is gone
(section 3).

`POST /restore/entries` runs two passes over its part, and every rule below is
a guard test:

- **Inspect, writing nothing:** the part's grammar and footer must check out;
  its header must name a part number of 1 or higher (a part 0 is refused);
  every `feedUrl` it names must be a feed the account subscribes to, checked
  against the database; and the account's current entry count across its
  subscribed feeds, plus this part's entries, must not pass 500,000.
- **Load:** an entry whose `(feed, guidHash)` already exists is skipped —
  this is what makes a retried part safe, and it also covers the refresh
  worker fetching the same feed while the restore is still running. A feed
  another account also subscribes to, with new entries switched off for this
  one, gets none of the part's entries. An article mark attaches to its
  article whether this request created the article or found it already
  there; if the account already holds a mark for that article, the file's
  mark is dropped and the existing one is left as is — a retried part, or a
  mark you set while the restore was running, is never overwritten. Every
  article this request creates reaches the search index before the request
  ends.

### 8.3 The guard tests

Read these before you change a backed-up entity or the restore endpoints.

- `backend/tests/Service/Backup/BackupSchemaCoverageTest.php` reads the ORM
  mapping and demands a decision for each persisted field of each backed-up
  entity. A new column on a backed-up table makes this test red. The test also
  asserts that this page names each dropped entity and each dropped field, so
  the dropped-field tables above (6.2, 6.3, 7) cannot fall behind the code. It
  also proves that a fully populated account never exports a backed-up field
  as null, so a field cannot pass this test merely because its test fixture
  left it empty.
- `backend/tests/Service/Backup/AccountRestorerTest.php::testEveryBackedUpFieldSurvivesTheRestoreRoundTrip`
  guards the other direction. It exports a fully populated account to parts,
  restores the foundation part with `AccountRestorer::start`, restores each
  entry part with `EntryPartRestorer::load`, and then compares source and
  target row by row. `BackupSchemaCoverageTest` proves that the exporter
  writes each backed-up field; it does not prove that a restore reads that
  field back. A field can pass the write-direction test and still get lost:
  the exporter writes it, but no Line DTO reads it back on restore. This test
  closes that gap. Both tests read the same field list, from
  `backend/tests/Support/BackupFieldDeclarations.php`. A field added to that
  list gets both tests for free.
- `backend/tests/Service/Backup/AccountBackupExporterTest.php` guards what the
  exporter writes.
- `backend/tests/Service/Backup/GoldenBackupRestoreTest.php` restores two
  frozen fixture directories on each run, `backend/tests/Fixtures/backup/current/`
  and `backend/tests/Fixtures/backup/oldest-supported/`, each holding a
  `000-foundation.ndjson` and a `001-entries.ndjson`. They guard what the
  reader still accepts. `oldest-supported/` is frozen the moment it is
  created; only `current/` moves when the format changes, and an additive
  field adds nothing to either fixture.
- `backend/tests/Service/Backup/EntryPartRestorerTest.php` guards the rules in
  section 8.2 directly, including
  `testARetriedPartCreatesNothingAndFailsNothing` (a resent part is a no-op)
  and `testAnExistingStateRowIsLeftUntouched` (a mark set during the restore
  always wins over the file).

Section 5 is different: no test couples it to the code. A field that stays
`BACKED_UP` never has to change section 5, so nothing forces a red test when a
new carried field is missing its row there. Keep it accurate by hand when you
add a row under "Where a new decision goes" below.

**The rule for an additive field.** When you add a field to the format, add
nothing to the golden corpus in `backend/tests/Fixtures/backup/`. The
`oldest-supported/` directory does not hold the new field already, and that
absence is the test: it proves that an older backup still restores. Add a
third directory only when support for something is dropped for the first
time.

**Where a new decision goes.** Put a new field in one of these lists in
`BackupSchemaCoverageTest`:

| List | Meaning |
|---|---|
| `BACKED_UP` | The file carries the field. Add the row to section 5 of this page. |
| `NOT_BACKED_UP` | The file drops the field. Add a row to section 6.3 with a reason a reader understands. |
| `NEVER_BACKED_UP` | A security boundary. Add a row to section 7. Moving a field out of this list is never the answer to a red test. |
| `ACCOUNT_SCOPED_WHOLLY_DROPPED` | A whole entity that belongs to the account and is dropped. Add a row to section 6.2. |
| `INSTANCE_SCOPED` | A whole entity that belongs to the instance. Add a row to section 6.1. |
