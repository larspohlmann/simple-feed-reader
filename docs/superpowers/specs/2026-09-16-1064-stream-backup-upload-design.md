# Stream Backup Uploads to a Temporary File (#1064)

## Problem

The account restore endpoints currently call `Request::getContent()`. Symfony
therefore returns the complete gzip body as a PHP string. `GzipLineReader`
copies that string into `php://memory` for every read. A restore reads the body
twice, first to validate and count it and then to load it.

A measured account backup is 36,129,862 bytes compressed and about 136 MB when
expanded. The current path therefore keeps about 70 MB of compressed data in
PHP memory before line decoding and restore work. The Strato web worker can die
near 200 MB. An out-of-memory failure produces no useful API response.

Issue #1063 raised the request-body ceiling to 64M. This change removes the
memory cost that otherwise grows with every accepted upload.

## Goals

- Read the HTTP body as a stream.
- Copy the upload to a disk-backed temporary file with constant PHP memory.
- Read validation and loading passes from the same bytes.
- Prefer the system temporary directory.
- Fall back to an application-owned directory when the system directory cannot
  create a file.
- Remove the temporary file after every success and failure path.
- Preserve all current backup validation, fit checks, destructive-ordering
  guarantees, and API responses.
- Keep peak PHP memory below the Strato worker cap and independent of the
  compressed upload size.

## Non-goals

- Change the 64M request-body ceiling.
- Change the backup format or schema version.
- Change restore limits, database batching, or account-reset behavior.
- Keep uploaded backups after the request.
- Add a user-visible setting for temporary storage.

## Considered approaches

### Managed temporary backup file

Copy the request resource to a managed file. Open a fresh read handle for each
pass. Keep file creation, fallback, and cleanup in one service.

This is the selected approach. It gives the two passes identical bytes, keeps
the controller thin, and makes failure cleanup testable.

### Direct `tmpfile()` calls in the restore services

This has fewer types, but it duplicates storage and cleanup logic in preview
and restore. It also does not provide the required application-directory
fallback cleanly.

### Always write below `backend/var`

This is deterministic, but it ignores the preferred system temporary
directory. It also puts all upload traffic inside the application tree when a
working operating-system location is available.

## Components

### `TemporaryBackupStorage`

This service owns the upload lifecycle.

Its public operation accepts the request-body resource and a callback. It
creates a `TemporaryBackupFile`, copies the body, calls the callback, and
deletes the file in `finally`. A generic callback return type keeps preview and
restore results typed. The service receives `LoggerInterface` for the one case
where cleanup fails while another exception is already active.

The service first tries `sys_get_temp_dir()`. Tests can provide another primary
directory. If file creation fails there, the service creates and tries
`%kernel.project_dir%/var/backup-restore` with owner-only directory permissions.
If both locations fail, it throws `BackupStorageException`.

File names use random bytes and exclusive creation. The service sets file
permissions to `0600`. It does not use `tempnam()`, because PHP can silently
place a file in the system directory when the requested directory fails. That
behavior would defeat the explicit fallback and its tests.

The service selects a location before it copies the upload. It does not retry a
partly consumed input stream in another directory. If copying fails, it removes
the partial file and throws `BackupStorageException`.

### `TemporaryBackupFile`

This object owns one temporary path. It opens a new read-only handle at the
start of each pass. It deletes the path when `TemporaryBackupStorage` closes
the operation. Deletion is idempotent. Its destructor is a final cleanup guard,
not the normal cleanup path.

The path is not exposed outside the backup package. Callers receive only a new
read handle. This prevents restore code from depending on storage placement.

### `BackupStorageException`

This typed exception reports server-side temporary-storage failure. It is not
an `InvalidBackupException`: the uploaded file is not at fault. Existing API
error handling returns a server error without exposing the filesystem path.

### Controller and restore services

`AccountBackupController` calls `Request::getContent(true)` for preview and
restore. It passes the resource to `RestorePreviewer` or `AccountRestorer`.

Both services use `TemporaryBackupStorage` for the scoped operation. The
confirmation guard in `AccountRestorer` runs before the upload is copied.

Inside the scope:

1. `RestorePreviewer` inspects the temporary file and builds the existing
   preview.
2. `AccountRestorer` inspects the temporary file, applies the fit check, resets
   the account, and passes the same file to `RestoreLoader`.

`BackupInspector`, `RestoreLoader`, and `BackupReader` accept a
`TemporaryBackupFile` instead of a gzip string.

### `GzipLineReader`

`BackupReader` asks `TemporaryBackupFile` for a fresh handle on each pass and
gives that handle to `GzipLineReader`. `GzipLineReader` checks the gzip magic,
adds the existing zlib inflate filter, yields lines, and closes the handle in
`finally`.

Each pass starts from a new handle at byte zero. No pass copies the gzip body
into a PHP string or an in-memory stream. The largest live input value is one
inflated line plus the stream filter's bounded buffer.

## Data flow

### Preview

1. Symfony exposes `php://input` as a resource.
2. `TemporaryBackupStorage` copies it to the preferred disk location.
3. `BackupInspector` reads the file once through `BackupReader`.
4. `RestorePreviewer` returns the current preview response.
5. `TemporaryBackupStorage` deletes the file in `finally`.

### Restore

1. `AccountRestorer` validates the `REPLACE` confirmation.
2. `TemporaryBackupStorage` copies the request resource to disk.
3. `BackupInspector` opens and reads pass 1.
4. The fit check runs before any account data is deleted.
5. `AccountReset` wipes the account.
6. `RestoreLoader` opens and reads pass 2 from the same file.
7. `TemporaryBackupStorage` deletes the file in `finally`.

## Failure handling

- A primary-directory creation failure selects the application fallback.
- A fallback-directory creation or file-creation failure throws
  `BackupStorageException`.
- A copy failure removes the partial file and throws
  `BackupStorageException`.
- Invalid gzip, invalid backup grammar, fit-check failure, database failure,
  and indexing failure keep their current exception behavior.
- Every callback exit runs the same `finally` cleanup.
- A cleanup failure after a successful callback throws
  `BackupStorageException`; a restore must not report success while its private
  upload remains on disk.
- A cleanup failure while another exception is active logs a critical error
  and preserves the original exception. The destructor then makes one final
  best-effort deletion attempt.
- No filesystem path appears in an API response.

## Tests

### Storage tests

Write failing tests first for these behaviors:

- The primary directory receives the exact uploaded bytes.
- A failed primary directory selects the application fallback.
- Failure of both directories throws `BackupStorageException`.
- Callback success leaves no temporary file.
- A callback exception leaves no temporary file and propagates unchanged.
- A copy failure leaves no partial file.
- Cleanup can run more than once.
- A cleanup failure after callback success throws `BackupStorageException`.
- A cleanup failure during another exception preserves that exception.
- Two read handles return the same bytes from byte zero.

The tests use private per-test directories. They assert effects, not internal
method calls.

### Reader and service tests

- Convert `GzipLineReaderTest` to real file handles and retain its long-line,
  corrupt-body, non-gzip, and empty-body cases.
- Convert `BackupReaderTest`, `BackupInspectorTest`, `RestorePreviewerTest`,
  `AccountRestorerTest`, and loader tests to real temporary backup files.
- Keep the destructive-route tests that prove invalid input never deletes the
  account.
- Keep the export-to-restore round trip through the real service graph.
- Keep the HTTP preview and restore tests. These prove that
  `Request::getContent(true)` reaches production wiring.
- Break the cleanup guard once and confirm that the cleanup tests fail for the
  named leftover file before restoring the implementation.

### Memory probe

Generate valid backup fixtures before measurement in a separate process. Use
many moderate entry lines so the probe does not hide the upload cost in one
exceptionally large JSON line.

Run each restore measurement in a fresh PHP process:

- about 36 MiB compressed, matching the measured account size;
- about 50 MiB compressed, well above the measured account and below the 64M
  request ceiling.

Measure the real preview and restore service paths. Both runs must stay below
180 MiB of PHP peak memory. The larger file may add no more than 16 MiB to the
smaller file's peak. These limits leave margin below the moving Strato worker
failure range and prove that compressed upload size is no longer held in PHP
memory.

The probe is diagnostic and does not add a large fixture to the repository.
Record its commands and results in the pull request.

### Project verification

- `php bin/phpunit`
- `composer check`
- `composer md`
- `composer infection:diff`
- MySQL tests through `docker compose exec php composer test`
- Dev and production Docker HTTP restores with a large generated backup when
  the branch can run from the main checkout
- An over-64M request still returns a readable nginx 413
- Scan the current development log after the Docker checks

## Security and operations

Backup files contain private account data. Temporary files use `0600`, and the
fallback directory uses owner-only permissions. Random exclusive file names
prevent collisions and replacement races.

The fallback directory can remain empty after a request. No backup file may
remain there. Normal deployment and cache cleanup do not need a new step.

This change does not alter authentication, routes, request media types, or
response formats. The native-client architecture remains unchanged: bearer
authentication, a raw `application/gzip` body, and JSON or problem JSON
responses.

## Delivery

The change stays on `fix/1064-stream-backup-upload` and targets `develop`.
The pull request closes #1064. It does not change or redeploy the 64M ceiling.
