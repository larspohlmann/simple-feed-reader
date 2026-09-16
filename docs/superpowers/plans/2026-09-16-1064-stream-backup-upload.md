# Stream Backup Uploads Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep account restore upload memory flat by copying the request stream to a managed temporary file and reading both restore passes from disk.

**Architecture:** `TemporaryBackupStorage` scopes a disk-backed `TemporaryBackupFile` around preview or restore. It prefers the system temporary directory, falls back to `backend/var/backup-restore`, and owns cleanup. The controller supplies a request resource; readers open a fresh file handle for each pass and inflate it without constructing a gzip string.

**Tech Stack:** PHP 8.4, Symfony 7.4 HttpFoundation and dependency injection, PHPUnit 12, Doctrine ORM, zlib stream filters.

**Spec:** `docs/superpowers/specs/2026-09-16-1064-stream-backup-upload-design.md`

## Global Constraints

- Keep the request-body ceiling at `64M`/`64m` in all five stack files.
- Keep the backup schema and wire format unchanged.
- Keep preview read-only and keep inspection and fit checks before `AccountReset`.
- Prefer `sys_get_temp_dir()` and fall back to `%kernel.project_dir%/var/backup-restore` only when primary file creation fails.
- Create fallback directories with mode `0700` and files with mode `0600`.
- Never expose a temporary path in an API response.
- Remove the temporary file after success and every exception path.
- Do not retry a partly consumed input stream in another directory.
- Use real components in tests. Do not assert on mocks.
- For every new guard, name the production mutation it catches and run that mutation once to prove the test fails.
- Run backend tests from the worktree's real `backend/vendor`; never symlink it to the main checkout.
- Do not run the shared Docker stack from this worktree. Perform Docker HTTP checks only after the branch can own the main checkout stack.

---

## File map

New production files:

- `backend/src/Service/Backup/TemporaryBackupStorage.php`: selects storage, copies the request stream, scopes callback execution, and coordinates cleanup.
- `backend/src/Service/Backup/TemporaryBackupFile.php`: owns one private path, opens fresh read handles, and deletes the path.
- `backend/src/Service/Backup/Exception/BackupStorageException.php`: typed server-side storage failure without a public path.

New test support and tests:

- `backend/tests/Service/Backup/TemporaryBackupStorageTest.php`: preferred path, fallback, byte preservation, and every cleanup branch.
- `backend/tests/Support/TemporaryBackupFixture.php`: adapts test byte strings to the real storage boundary without adding test-only production APIs.

Modified production files:

- `backend/src/Controller/Api/AccountBackupController.php`: request body resource instead of string.
- `backend/src/Service/Backup/GzipLineReader.php`: inflate from an owned read handle.
- `backend/src/Service/Backup/BackupReader.php`: read a `TemporaryBackupFile`.
- `backend/src/Service/Backup/BackupInspector.php`: inspect a `TemporaryBackupFile`.
- `backend/src/Service/Backup/RestoreLoader.php`: load a `TemporaryBackupFile`.
- `backend/src/Service/Backup/RestorePreviewer.php`: scope preview inside `TemporaryBackupStorage`.
- `backend/src/Service/Backup/AccountRestorer.php`: scope both restore passes around the same file.

Modified tests:

- `backend/tests/Service/Backup/GzipLineReaderTest.php`
- `backend/tests/Service/Backup/BackupReaderTest.php`
- `backend/tests/Service/Backup/BackupInspectorTest.php`
- `backend/tests/Service/Backup/RestorePreviewerTest.php`
- `backend/tests/Service/Backup/AccountRestorerTest.php`
- `backend/tests/Service/Backup/GoldenBackupRestoreTest.php`
- `backend/tests/Controller/Api/AccountBackupControllerTest.php`

---

### Task 1: Managed temporary backup lifecycle

**Files:**

- Create: `backend/src/Service/Backup/Exception/BackupStorageException.php`
- Create: `backend/src/Service/Backup/TemporaryBackupFile.php`
- Create: `backend/src/Service/Backup/TemporaryBackupStorage.php`
- Create: `backend/tests/Service/Backup/TemporaryBackupStorageTest.php`

**Interfaces:**

- Consumes: a readable PHP resource from `Request::getContent(true)`.
- Produces: `TemporaryBackupStorage::withFile(mixed $uploadStream, callable $operation): mixed` with a generic callback result.
- Produces: `TemporaryBackupFile::open(): mixed`, documented as a fresh read-only resource at byte zero.
- Produces: `TemporaryBackupFile::delete(): void`, which is idempotent.
- Produces: `BackupStorageException` factories for create, copy, open, and delete failures.

- [ ] **Step 1: Write lifecycle tests that fail because the storage types do not exist**

Create `TemporaryBackupStorageTest.php`. Use one private directory per test and remove it in `tearDown()`. The tests must cover these observable behaviors:

Keep every resource returned by the test's `source()` helper in a list. Close
all still-open source resources in `tearDown()` before removing the private
directories. Production storage owns the destination file, not the caller's
request resource.

```php
public function testCopiesExactBytesIntoThePrimaryDirectory(): void
{
    $result = $this->storage()->withFile(
        $this->source("gzip-bytes\x00\xff"),
        function (TemporaryBackupFile $file): string {
            self::assertSame(1, $this->entryCount($this->primaryDirectory));
            self::assertSame(0, $this->entryCount($this->fallbackDirectory));
            $stream = $file->open();
            try {
                return (string) stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
        },
    );

    self::assertSame("gzip-bytes\x00\xff", $result);
    self::assertSame(0, $this->entryCount($this->primaryDirectory));
}

public function testFallsBackWhenThePrimaryPathCannotContainAFile(): void
{
    file_put_contents($this->primaryDirectory, 'not a directory');

    $this->storage()->withFile($this->source('bytes'), function (): void {
        self::assertSame(1, $this->entryCount($this->fallbackDirectory));
    });

    self::assertSame(0, $this->entryCount($this->fallbackDirectory));
}

public function testThrowsWhenNeitherDirectoryCanContainAFile(): void
{
    file_put_contents($this->primaryDirectory, 'not a directory');
    file_put_contents($this->fallbackDirectory, 'not a directory');

    $this->expectException(BackupStorageException::class);
    $this->storage()->withFile($this->source('bytes'), static fn (): null => null);
}

public function testDeletesTheFileWhenTheCallbackThrows(): void
{
    $failure = new \RuntimeException('operation failed');

    try {
        $this->storage()->withFile(
            $this->source('bytes'),
            static function () use ($failure): never {
                throw $failure;
            },
        );
        self::fail('The callback exception was swallowed.');
    } catch (\RuntimeException $caught) {
        self::assertSame($failure, $caught);
    }

    self::assertSame(0, $this->entryCount($this->primaryDirectory));
}

public function testTwoHandlesStartAtByteZero(): void
{
    $this->storage()->withFile($this->source('same bytes'), static function (TemporaryBackupFile $file): void {
        $first = $file->open();
        $second = $file->open();
        try {
            self::assertSame('same bytes', stream_get_contents($first));
            self::assertSame('same bytes', stream_get_contents($second));
        } finally {
            fclose($first);
            fclose($second);
        }
    });
}
```

Add tests for a closed source resource, idempotent `delete()`, cleanup failure after callback success, and cleanup failure while the callback throws. To force cleanup failure, obtain the path from `stream_get_meta_data($file->open())['uri']`, close the handle, replace the file with a directory at that path, and remove that directory in the test's `finally` block. Use `RecordingLogger` to prove the active callback exception is preserved and one critical cleanup record is written.

The production mutations named by this test group are: omit fallback selection, copy a different byte sequence, omit `finally`, reuse one advanced handle, or let cleanup replace an active callback exception.

- [ ] **Step 2: Run the storage tests and verify the missing-type failure**

Run:

```bash
php bin/phpunit tests/Service/Backup/TemporaryBackupStorageTest.php
```

Expected: error because `TemporaryBackupStorage`, `TemporaryBackupFile`, and `BackupStorageException` do not exist.

- [ ] **Step 3: Implement the typed exception and temporary file**

Create a final `BackupStorageException` under the existing backup exception namespace. Its public messages must not include paths:

```php
final class BackupStorageException extends \RuntimeException
{
    public static function cannotCreate(?\Throwable $previous = null): self
    {
        return new self('The backup upload cannot be stored temporarily.', 0, $previous);
    }

    public static function cannotCopy(\Throwable $previous): self
    {
        return new self('The backup upload cannot be written to temporary storage.', 0, $previous);
    }

    public static function cannotOpen(?\Throwable $previous = null): self
    {
        return new self('The stored backup upload cannot be read.', 0, $previous);
    }

    public static function cannotDelete(): self
    {
        return new self('The stored backup upload cannot be removed.');
    }
}
```

Implement `TemporaryBackupFile` as a final mutable lifecycle object. Store the path in a readonly constructor property and a separate deleted flag. `open()` uses `@fopen($path, 'rb')` and throws `cannotOpen()` on failure. `delete()` returns immediately after the first successful delete, treats an already missing path as deleted, and throws `cannotDelete()` when `@unlink()` fails. `__destruct()` performs only `@unlink()` when explicit cleanup did not complete.

- [ ] **Step 4: Implement primary selection, fallback, copy, and scoped cleanup**

Construct `TemporaryBackupStorage` with these dependencies:

```php
public function __construct(
    #[Autowire('%kernel.project_dir%/var/backup-restore')]
    private string $fallbackDirectory,
    private LoggerInterface $logger,
    private ?string $primaryDirectory = null,
) {
}
```

Use `primaryDirectory ?? sys_get_temp_dir()`. Try three random exclusive names per directory with `@fopen($path, 'x+b')`. The name format is `backup-` plus 24 hexadecimal random characters plus `.gz`. Do not create the primary directory. Create the fallback with `@mkdir($path, 0700, true) || is_dir($path)`. Reject a file unless `@chmod($path, 0600)` succeeds.

The scoped method has this contract:

```php
/**
 * @template T
 * @param resource $uploadStream
 * @param callable(TemporaryBackupFile): T $operation
 * @return T
 */
public function withFile($uploadStream, callable $operation): mixed
```

Copy with `stream_copy_to_stream()`. Catch every `Throwable` from the copy, close the destination, remove the partial file, and throw `BackupStorageException::cannotCopy($error)`. Close the destination before constructing `TemporaryBackupFile`.

Track the callback exception in a local variable. In `finally`, call `delete()`. If delete fails without an active callback exception, rethrow the storage exception. If another exception is active, log one critical record with the cleanup exception and preserve the callback exception.

- [ ] **Step 5: Run storage tests and verify they pass**

Run:

```bash
php bin/phpunit tests/Service/Backup/TemporaryBackupStorageTest.php
```

Expected: all storage tests pass with no warning, notice, or leftover file.

- [ ] **Step 6: Break and restore the cleanup guard**

Make a scratch copy of `TemporaryBackupStorage.php`. Temporarily remove the `finally` cleanup call. Run only `testDeletesTheFileWhenTheCallbackThrows` and confirm it fails because one file remains. Restore the scratch copy without using `git checkout --`, then rerun the test and confirm it passes.

- [ ] **Step 7: Commit the lifecycle unit**

```bash
git add backend/src/Service/Backup/Exception/BackupStorageException.php backend/src/Service/Backup/TemporaryBackupFile.php backend/src/Service/Backup/TemporaryBackupStorage.php backend/tests/Service/Backup/TemporaryBackupStorageTest.php
git commit -m "feat(#1064): add managed temporary backup storage"
```

---

### Task 2: Stream gzip parsing from fresh file handles

**Files:**

- Create: `backend/tests/Support/TemporaryBackupFixture.php`
- Modify: `backend/src/Service/Backup/GzipLineReader.php:9-79`
- Modify: `backend/src/Service/Backup/BackupReader.php:60-72`
- Modify: `backend/tests/Service/Backup/GzipLineReaderTest.php`
- Modify: `backend/tests/Service/Backup/BackupReaderTest.php`
- Modify: `backend/tests/Service/Backup/BackupInspectorTest.php`

**Interfaces:**

- Consumes: `TemporaryBackupFile::open(): resource` from Task 1.
- Produces: `GzipLineReader::lines(mixed $gzipStream): Generator<int, string>`; it owns and closes the supplied handle.
- Produces: `BackupReader::read(TemporaryBackupFile $backup): Generator<int, object>`.
- Produces: `TemporaryBackupFixture::withBytes(string $bytes, callable $operation): mixed` for tests only.

- [ ] **Step 1: Add the real-file test fixture adapter**

Create `TemporaryBackupFixture` in `App\Tests\Support`. It must create a unique directory under `sys_get_temp_dir()`, write the provided bytes through a real `TemporaryBackupStorage`, call the operation, close the source resource, and remove the empty directory in `finally`.

Use this signature:

```php
/**
 * @template T
 * @param callable(TemporaryBackupFile): T $operation
 * @return T
 */
public static function withBytes(string $bytes, callable $operation): mixed
```

Write the source into `php://temp`, rewind it, and pass a `NullLogger` to storage. Use the same unique directory as primary and fallback because this helper tests readers, not fallback selection.

- [ ] **Step 2: Change reader tests first and verify they fail on string-only APIs**

Convert `GzipLineReaderTest` calls to:

```php
$lines = TemporaryBackupFixture::withBytes(
    $gzip,
    static fn (TemporaryBackupFile $file): array => iterator_to_array(
        GzipLineReader::lines($file->open()),
        false,
    ),
);
```

Convert `BackupReaderTest` through one local helper:

```php
/** @return list<object> */
private static function read(string $gzip): array
{
    return TemporaryBackupFixture::withBytes(
        $gzip,
        static fn (TemporaryBackupFile $file): array => iterator_to_array(
            new BackupReader()->read($file),
            false,
        ),
    );
}
```

For exception cases, add a local `consume(string $gzip): void` helper that executes the generator inside `withBytes()` so cleanup occurs after the exception.

Convert `BackupInspectorTest` through a local helper that calls `inspect($file)` inside `withBytes()`.

Run:

```bash
php bin/phpunit tests/Service/Backup/GzipLineReaderTest.php tests/Service/Backup/BackupReaderTest.php tests/Service/Backup/BackupInspectorTest.php
```

Expected: type failures because production readers still require strings.

- [ ] **Step 3: Change `GzipLineReader` to own a supplied file handle**

Change the public signature and docblock:

```php
/**
 * @param resource $gzipStream
 * @return \Generator<int, string>
 * @throws InvalidBackupException
 */
public static function lines($gzipStream): \Generator
```

Inside one `try/finally`:

1. Read exactly two bytes with `fread($gzipStream, 2)`.
2. Refuse a non-string or wrong magic value with the existing invalid-backup message.
3. Require `rewind($gzipStream)` to succeed.
4. Add the existing `zlib.inflate` read filter with window `15 + 32`.
5. Throw `RuntimeException('Cannot inflate the stored backup upload.')` if filter creation fails.
6. Yield the same newline-trimmed lines through the existing protected `readLine()` call.
7. Close the supplied handle in `finally`.

Remove all `php://memory`, `fwrite()`, and gzip-string code. Update the class comment to state that each pass receives a fresh file handle and keeps only the current inflated line in PHP memory.

- [ ] **Step 4: Change `BackupReader` to open each pass from `TemporaryBackupFile`**

Change `read(string $gzipBytes)` to:

```php
public function read(TemporaryBackupFile $backup): \Generator
```

Replace the line source with:

```php
foreach (GzipLineReader::lines($backup->open()) as $line) {
```

Do not change grammar, DTO conversion, footer validation, or error messages.

Change `BackupInspector::inspect()` to accept `TemporaryBackupFile` and pass it to `BackupReader`. This small signature change belongs here because its tests directly prove the first pass.

- [ ] **Step 5: Run reader and inspector tests**

Run:

```bash
php bin/phpunit tests/Service/Backup/GzipLineReaderTest.php tests/Service/Backup/BackupReaderTest.php tests/Service/Backup/BackupInspectorTest.php
```

Expected: all tests pass, including the 2,000,000-byte line, non-gzip, corrupt gzip, empty input, footer, ordering, and tally cases.

- [ ] **Step 6: Commit the streaming reader unit**

```bash
git add backend/src/Service/Backup/GzipLineReader.php backend/src/Service/Backup/BackupReader.php backend/src/Service/Backup/BackupInspector.php backend/tests/Support/TemporaryBackupFixture.php backend/tests/Service/Backup/GzipLineReaderTest.php backend/tests/Service/Backup/BackupReaderTest.php backend/tests/Service/Backup/BackupInspectorTest.php
git commit -m "refactor(#1064): read backup gzip from file handles"
```

---

### Task 3: Wire preview and restore to the scoped upload stream

**Files:**

- Modify: `backend/src/Controller/Api/AccountBackupController.php:40-54`
- Modify: `backend/src/Service/Backup/RestorePreviewer.php:20-47`
- Modify: `backend/src/Service/Backup/AccountRestorer.php:12-58`
- Modify: `backend/src/Service/Backup/RestoreLoader.php:24-54`
- Modify: `backend/tests/Service/Backup/RestorePreviewerTest.php`
- Modify: `backend/tests/Service/Backup/AccountRestorerTest.php`
- Modify: `backend/tests/Service/Backup/GoldenBackupRestoreTest.php`
- Modify: `backend/tests/Controller/Api/AccountBackupControllerTest.php`

**Interfaces:**

- Consumes: `TemporaryBackupStorage::withFile()` and `TemporaryBackupFile` from Task 1.
- Consumes: `BackupInspector::inspect(TemporaryBackupFile)` and `BackupReader::read(TemporaryBackupFile)` from Task 2.
- Produces: `RestorePreviewer::preview(User $user, mixed $uploadStream): RestorePreview`, with `@param resource $uploadStream`.
- Produces: `AccountRestorer::restore(User $user, mixed $uploadStream, ?string $confirmation): RestoreResult`, with `@param resource $uploadStream`.
- Produces: `RestoreLoader::load(User $user, TemporaryBackupFile $backup): RestoreResult`.

- [ ] **Step 1: Convert service tests to request-like resources before production code**

In `RestorePreviewerTest`, add:

```php
private function preview(User $user, string $gzip): RestorePreview
{
    $stream = self::uploadStream($gzip);
    try {
        return $this->previewer()->preview($user, $stream);
    } finally {
        fclose($stream);
    }
}
```

Add one static `uploadStream(string $bytes)` helper that writes to `php://temp`, rewinds, asserts a resource, and returns it. Replace the three direct `preview()` calls with this helper.

In `AccountRestorerTest`, add one `restore(AccountRestorer $restorer, User $user, string $gzip, ?string $confirmation): RestoreResult` helper. It creates and closes the same request-like stream. Replace every direct call, including both calls in the idempotency test and the custom-indexer restorer.

In `GoldenBackupRestoreTest`, add the same small stream adapter and route both fixture restores through it.

Run:

```bash
php bin/phpunit tests/Service/Backup/RestorePreviewerTest.php tests/Service/Backup/AccountRestorerTest.php tests/Service/Backup/GoldenBackupRestoreTest.php
```

Expected: type failures because services still require gzip strings.

- [ ] **Step 2: Scope preview around `TemporaryBackupStorage`**

Inject `TemporaryBackupStorage` into `RestorePreviewer` after `BackupFitCheck`.
Change `preview()` to accept a documented resource and return:

```php
return $this->storage->withFile(
    $uploadStream,
    fn (TemporaryBackupFile $backup): RestorePreview => $this->previewFile($user, $backup),
);
```

Move the existing inventory, fit-check, count queries, and result construction unchanged into:

```php
private function previewFile(User $user, TemporaryBackupFile $backup): RestorePreview
```

- [ ] **Step 3: Scope both destructive passes around one file**

Inject `TemporaryBackupStorage` into `AccountRestorer` after `BackupFitCheck`.
Keep the `REPLACE` guard at the start of the public method, before storage.
Then return:

```php
return $this->storage->withFile(
    $uploadStream,
    fn (TemporaryBackupFile $backup): RestoreResult => $this->restoreFile($user, $backup),
);
```

Move the existing inspect, fit check, user id capture, account reset, refresh, and load sequence unchanged into:

```php
private function restoreFile(User $user, TemporaryBackupFile $backup): RestoreResult
```

Pass the same `$backup` object to inspection and loading. Update the class comment from "in-memory gzip string" to "same disk-backed temporary file".

Change `RestoreLoader::load()` to accept `TemporaryBackupFile` and pass it to `BackupReader`.

- [ ] **Step 4: Make the HTTP boundary provide a resource**

In both controller actions, change only:

```php
$request->getContent()
```

to:

```php
$request->getContent(true)
```

Keep the controller's confirmation parsing and JSON mapping unchanged.

- [ ] **Step 5: Add an HTTP cleanup assertion**

In `AccountBackupControllerTest`, determine the fallback directory from `kernel.project_dir . '/var/backup-restore'`. Before and after the existing successful preview, successful restore, corrupt preview, and corrupt destructive restore tests, assert that this directory contains no `backup-*.gz` file.

Do not assert that production used the fallback. The system temp directory is expected to win. This assertion is a backstop against a file that reaches the application directory and survives a request.

- [ ] **Step 6: Update the custom service graph and run focused tests**

`AccountRestorerTest::restorerIndexingInto()` constructs `AccountRestorer` manually. Add a real `TemporaryBackupStorage` with `kernel.project_dir . '/var/backup-restore'`, `NullLogger`, and a unique test primary directory. Remove that primary directory after the custom restore helper returns.

Run:

```bash
php bin/phpunit tests/Service/Backup/RestorePreviewerTest.php tests/Service/Backup/AccountRestorerTest.php tests/Service/Backup/GoldenBackupRestoreTest.php tests/Controller/Api/AccountBackupControllerTest.php
```

Expected: all preview, validation, destructive-ordering, round-trip, corrupt-gzip, authentication, and HTTP tests pass.

- [ ] **Step 7: Run all backup tests and prove the string path is gone**

Run:

```bash
php bin/phpunit tests/Service/Backup tests/Controller/Api/AccountBackupControllerTest.php
rg -n "getContent\(\)|string \$gzipBytes|php://memory" src/Controller/Api/AccountBackupController.php src/Service/Backup tests/Service/Backup
```

Expected: the test command passes. The search returns no production restore-body string, no `string $gzipBytes` restore signatures, and no `php://memory` in the backup package. Test fixture helpers may still hold small gzip strings before they cross the production upload boundary.

- [ ] **Step 8: Commit the restore-pipeline unit**

```bash
git add backend/src/Controller/Api/AccountBackupController.php backend/src/Service/Backup/RestorePreviewer.php backend/src/Service/Backup/AccountRestorer.php backend/src/Service/Backup/RestoreLoader.php backend/tests/Service/Backup/RestorePreviewerTest.php backend/tests/Service/Backup/AccountRestorerTest.php backend/tests/Service/Backup/GoldenBackupRestoreTest.php backend/tests/Controller/Api/AccountBackupControllerTest.php
git commit -m "fix(#1064): stream restore uploads through temporary files"
```

---

### Task 4: Large-backup memory proof

**Files:**

- Create temporarily, do not commit: `/private/tmp/1064-generate-backup.php`
- Create temporarily, do not commit: `backend/tests/Service/Backup/ManualBackupMemoryProbeTest.php`
- Record results in: pull request body for #1064

**Interfaces:**

- Consumes: the complete production service graph from Task 3.
- Produces: two compressed fixture sizes and isolated-process peak-memory results for real `AccountRestorer` calls.

- [ ] **Step 1: Generate two valid many-line backups outside the repository**

Create `/private/tmp/1064-generate-backup.php` with `apply_patch`. The script accepts an entry count and output path. It writes gzip NDJSON with this exact order:

1. schema-version-2 header;
2. one account;
3. one feed at `https://memory-probe.example/feed.xml`;
4. one subscription for that feed;
5. the requested number of entries, each with a unique `guid`, SHA-256 `guidHash`, and `contentHtml` containing `base64_encode(random_bytes(3000))`;
6. a footer with feed `1`, subscription `1`, the requested entry count, and all other counts `0`.

Each line is `json_encode($line, JSON_THROW_ON_ERROR) . "\n"` written with `gzwrite()`. The script must close the gzip handle and print the final byte size from `filesize()`.

Use this complete generator body:

```php
<?php

declare(strict_types=1);

if (3 !== $argc) {
    fwrite(STDERR, "Usage: php 1064-generate-backup.php <entry-count> <output>\n");
    exit(2);
}

$entryCount = filter_var($argv[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (false === $entryCount) {
    fwrite(STDERR, "Entry count must be a positive integer.\n");
    exit(2);
}

$output = $argv[2];
$gzip = gzopen($output, 'wb9');
if (false === $gzip) {
    throw new RuntimeException('Cannot create the probe backup.');
}

$write = static function (array $line) use ($gzip): void {
    $encoded = json_encode($line, JSON_THROW_ON_ERROR) . "\n";
    if (strlen($encoded) !== gzwrite($gzip, $encoded)) {
        throw new RuntimeException('Cannot write the probe backup.');
    }
};

$feedUrl = 'https://memory-probe.example/feed.xml';
$write([
    'kind' => 'header',
    'schemaVersion' => 2,
    'createdAt' => '2026-09-16T09:00:00+00:00',
    'sourceUrl' => 'https://memory-probe.example',
    'sourceEmail' => 'memory-probe@example.com',
]);
$write([
    'kind' => 'account',
    'locale' => 'en',
    'scrapeFallbackEnabled' => false,
    'magazineStyle' => 'boxed',
]);
$write([
    'kind' => 'feed',
    'url' => $feedUrl,
    'siteUrl' => 'https://memory-probe.example',
    'title' => 'Memory probe',
    'description' => null,
    'faviconUrl' => null,
    'imageUrl' => null,
    'sourceFormat' => 'xml',
]);
$write([
    'kind' => 'subscription',
    'feedUrl' => $feedUrl,
    'customTitle' => null,
    'position' => 0,
    'markedReadUntil' => null,
    'createdAt' => '2026-09-16T09:00:00+00:00',
    'tags' => [],
    'includeInAllItems' => true,
    'includeInForYou' => true,
]);

for ($index = 0; $index < $entryCount; ++$index) {
    $guid = 'memory-probe-' . $index;
    $write([
        'kind' => 'entry',
        'feedUrl' => $feedUrl,
        'guid' => $guid,
        'guidHash' => hash('sha256', $guid),
        'url' => 'https://memory-probe.example/entry/' . $index,
        'title' => 'Memory probe ' . $index,
        'author' => null,
        'summary' => null,
        'contentHtml' => '<p>' . base64_encode(random_bytes(3000)) . '</p>',
        'imageUrl' => null,
        'imageWidth' => null,
        'imageHeight' => null,
        'publishedAt' => null,
        'createdAt' => '2026-09-16T09:00:00+00:00',
        'effectiveDate' => '2026-09-16T09:00:00+00:00',
        'media' => [],
        'attachments' => [],
    ]);
}

$write([
    'kind' => 'footer',
    'counts' => [
        'tag' => 0,
        'savedSearch' => 0,
        'feed' => 1,
        'subscription' => 1,
        'entry' => $entryCount,
        'entryState' => 0,
    ],
]);

gzclose($gzip);
clearstatcache(true, $output);
fwrite(STDOUT, sprintf("%s %d bytes\n", $output, filesize($output)));
```

Run it first with 12,000 and 17,000 entries:

```bash
php /private/tmp/1064-generate-backup.php 12000 /private/tmp/1064-36m.gz
php /private/tmp/1064-generate-backup.php 17000 /private/tmp/1064-50m.gz
wc -c /private/tmp/1064-36m.gz /private/tmp/1064-50m.gz
```

If either result is outside 32-40 MiB or 46-56 MiB, change only its entry count in proportion to `target bytes / actual bytes`, regenerate once, and record the final counts and sizes.

- [ ] **Step 2: Add a temporary isolated restore probe**

Create `ManualBackupMemoryProbeTest.php` with `apply_patch`. It extends `DbTestCase`, creates one target user through `UserFactory`, reads `BACKUP_PROBE_FILE`, opens it as `rb`, fetches the real `AccountRestorer` from the container, and calls:

```php
$result = $restorer->restore($user, $stream, 'REPLACE');
fwrite(STDERR, sprintf("BACKUP_PEAK_BYTES=%d\n", memory_get_peak_usage(true)));
self::assertGreaterThan(0, $result->entries);
```

Close the source stream in `finally`. The test contains no fixture generator, so fixture creation cannot pollute the measured peak.

Use `setUp()` to fetch `UserPasswordHasherInterface` and construct
`UserFactory`, matching `AccountRestorerTest`. The test method body is:

```php
public function testMeasuresARealRestore(): void
{
    $path = getenv('BACKUP_PROBE_FILE');
    self::assertIsString($path);
    self::assertFileExists($path);

    $stream = fopen($path, 'rb');
    self::assertIsResource($stream);
    $user = $this->users->create('memory-probe-target@example.com');
    $restorer = self::getContainer()->get(AccountRestorer::class);
    self::assertInstanceOf(AccountRestorer::class, $restorer);

    try {
        $result = $restorer->restore($user, $stream, 'REPLACE');
    } finally {
        fclose($stream);
    }

    fwrite(STDERR, sprintf("BACKUP_PEAK_BYTES=%d\n", memory_get_peak_usage(true)));
    self::assertGreaterThan(0, $result->entries);
}
```

- [ ] **Step 3: Run each measurement in a fresh process**

Run each command separately:

```bash
BACKUP_PROBE_FILE=/private/tmp/1064-36m.gz php bin/phpunit tests/Service/Backup/ManualBackupMemoryProbeTest.php
BACKUP_PROBE_FILE=/private/tmp/1064-50m.gz php bin/phpunit tests/Service/Backup/ManualBackupMemoryProbeTest.php
```

Expected for both: restore passes and reports `BACKUP_PEAK_BYTES` below `188743680` bytes (180 MiB). The larger file's peak is no more than `16777216` bytes (16 MiB) above the smaller file's peak.

Inspect the primary and fallback directories after each run:

```bash
find "$(php -r 'echo sys_get_temp_dir();')" -maxdepth 1 -name 'backup-*.gz' -print
find var/backup-restore -maxdepth 1 -name 'backup-*.gz' -print 2>/dev/null
```

Expected: no output.

- [ ] **Step 4: Remove both temporary probe files from the worktree and rerun status**

Use `apply_patch` to delete `ManualBackupMemoryProbeTest.php`. Delete the two generated gzip files and generator from `/private/tmp` only after recording their byte sizes and peaks. Confirm:

```bash
rm /private/tmp/1064-36m.gz /private/tmp/1064-50m.gz /private/tmp/1064-generate-backup.php
```

```bash
git status --short
```

Expected: no manual probe file or generated fixture appears. Do not commit diagnostic files.

- [ ] **Step 5: Record the measurement in the future PR body**

Record both compressed sizes, both `BACKUP_PEAK_BYTES` values, their difference, the no-leftover-file result, and the exact two PHPUnit commands. Do not claim Strato production verification; these are isolated local PHP measurements.

---

### Task 5: Full verification and delivery readiness

**Files:**

- Modify only if a gate finds a real issue: files already listed in Tasks 1-3.
- Do not change: the five 64M request-limit files.

**Interfaces:**

- Consumes: all earlier task commits.
- Produces: a clean branch ready for review, with local limitations stated exactly.

- [ ] **Step 1: Simplify only changed production and test code**

Apply the `simplify` skill to the files changed in Tasks 1-3. Remove duplicated lifecycle code, unclear names, and comments that restate code. Do not merge `TemporaryBackupStorage` and `TemporaryBackupFile`; they own different responsibilities.

- [ ] **Step 2: Warm the development cache and run quality gates**

Run:

```bash
php bin/console cache:warmup
composer check
composer md
```

Expected: PHPCS, PHPStan, phptramp, and PHPMD all pass. Any OpenTelemetry no-extension message is the known local autoload warning; no tool may exit non-zero.

- [ ] **Step 3: Run full native tests**

Run:

```bash
php bin/phpunit
```

Expected: zero failures and zero errors. Record the test and assertion counts.

- [ ] **Step 4: Run changed-file mutation testing**

Run:

```bash
composer infection:diff
```

Expected: the configured changed-lines MSI gate passes. Fix escaped mutations that expose missing behavior; do not lower the threshold.

- [ ] **Step 5: Inspect the final diff and request review**

Run:

```bash
git diff origin/develop...HEAD --check
git diff origin/develop...HEAD --stat
git status --short --branch
```

Use the `superpowers:requesting-code-review` workflow. Fix every Critical and Important finding. Rerun the focused tests for each fix, then rerun Steps 2-4.

- [ ] **Step 6: Commit review-driven cleanup if needed**

Stage only the files changed by the review fix and use:

```bash
git commit -m "refactor(#1064): simplify temporary backup streaming"
```

Skip this commit when review needs no change.

- [ ] **Step 7: State Docker verification limits**

The worktree cannot safely drive the shared Docker HTTP stack. Before the PR merges, run these checks only from a main checkout that owns freshly rebuilt dev and production stacks:

- restore a generated 36 MiB backup through dev HTTP;
- restore it through the production Docker image;
- post a body above 64 MiB and confirm a readable nginx 413;
- run `docker compose exec php composer test` for MySQL;
- scan the current `backend/var/log/dev-YYYY-MM-DD.log` after the HTTP checks.

If that main-checkout window is not available, list these checks under `Not run` in the PR. Do not imply that native tests prove nginx, PHP-FPM, or production-image behavior.

- [ ] **Step 8: Use the branch-finishing workflow**

Use `superpowers:finishing-a-development-branch`. The intended base is `develop`. Do not merge, tag, deploy, or write to production without a new explicit user instruction.
