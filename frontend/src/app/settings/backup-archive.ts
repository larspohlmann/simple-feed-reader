import { BlobReader, BlobWriter, ZipReader, type Entry, type FileEntry } from '@zip.js/zip.js';
import { readPartHeader, type BackupPartHeader, type ReadPartHeader } from './backup-part-header';

export class InvalidBackupArchiveError extends Error {}

export interface BackupArchive {
  readonly entryPartCount: number;
  foundation(): Promise<Blob>;
  entryPart(index: number): Promise<Blob>;
  close(): Promise<void>;
}

export const isOldFormatBackup = (name: string): boolean => /\.json\.gz$/i.test(name);

const FOUNDATION_NAME = '000-foundation.ndjson.gz';
const ENTRY_PART_PATTERN = /^(\d{3})-entries\.ndjson\.gz$/;

export async function openBackupArchive(
  file: Blob,
  readHeader: ReadPartHeader = readPartHeader,
): Promise<BackupArchive> {
  const { reader, entries } = await openZip(file);
  try {
    const { foundation, entryParts } = classifyEntries(entries);
    await verifyArchive(foundation, entryParts, readHeader);
    return buildArchive(reader, foundation, entryParts);
  } catch (error) {
    await reader.close();
    throw error;
  }
}

async function openZip(file: Blob): Promise<{ reader: ZipReader<Blob>; entries: Entry[] }> {
  const reader = new ZipReader(new BlobReader(file));
  try {
    return { reader, entries: await reader.getEntries() };
  } catch {
    await reader.close();
    throw new InvalidBackupArchiveError('The file is not a valid zip archive.');
  }
}

function classifyEntries(entries: readonly Entry[]): {
  foundation: FileEntry;
  entryParts: FileEntry[];
} {
  const fileEntries = entries.map(requireFileEntry);
  const foundation = fileEntries.find((entry) => entry.filename === FOUNDATION_NAME);
  if (!foundation) {
    throw new InvalidBackupArchiveError('The archive has no foundation part.');
  }
  const entryParts = fileEntries.filter((entry) => entry !== foundation);
  return { foundation, entryParts: orderEntryParts(entryParts) };
}

function requireFileEntry(entry: Entry): FileEntry {
  if (entry.directory) {
    throw new InvalidBackupArchiveError(`Unexpected archive member: ${entry.filename}`);
  }
  return entry;
}

function orderEntryParts(entryParts: readonly FileEntry[]): FileEntry[] {
  const indexed = entryParts
    .map((entry) => ({ entry, index: parseEntryPartIndex(entry.filename) }))
    .sort((first, second) => first.index - second.index);
  assertContiguousIndexes(indexed.map((item) => item.index));
  return indexed.map((item) => item.entry);
}

function parseEntryPartIndex(filename: string): number {
  const match = ENTRY_PART_PATTERN.exec(filename);
  if (!match) {
    throw new InvalidBackupArchiveError(`Unexpected archive member: ${filename}`);
  }
  return Number(match[1]);
}

function assertContiguousIndexes(indexes: readonly number[]): void {
  const isContiguous = indexes.every((index, position) => index === position + 1);
  if (!isContiguous) {
    throw new InvalidBackupArchiveError('The archive entry parts are not contiguous.');
  }
}

async function verifyArchive(
  foundation: FileEntry,
  entryParts: readonly FileEntry[],
  readHeader: ReadPartHeader,
): Promise<void> {
  const foundationHeader = await readVerifiedHeader(foundation, readHeader);
  for (const [position, entry] of entryParts.entries()) {
    const header = await readVerifiedHeader(entry, readHeader);
    assertMatchesFoundation(header, foundationHeader, position + 1);
  }
  assertFoundationCountsAllParts(foundationHeader, entryParts.length);
}

async function readVerifiedHeader(
  entry: FileEntry,
  readHeader: ReadPartHeader,
): Promise<BackupPartHeader> {
  const blob = await extractVerifiedBlob(entry);
  return readHeader(blob);
}

async function extractVerifiedBlob(entry: FileEntry): Promise<Blob> {
  try {
    return await entry.getData(new BlobWriter('application/gzip'), { checkSignature: true });
  } catch {
    throw new InvalidBackupArchiveError(
      `The archive member "${entry.filename}" failed verification.`,
    );
  }
}

function assertMatchesFoundation(
  header: BackupPartHeader,
  foundation: BackupPartHeader,
  expectedPart: number,
): void {
  if (header.backupId !== foundation.backupId) {
    throw new InvalidBackupArchiveError('The archive mixes parts from different backups.');
  }
  if (header.part !== expectedPart) {
    throw new InvalidBackupArchiveError('The archive part index does not match its member name.');
  }
}

function assertFoundationCountsAllParts(
  foundation: BackupPartHeader,
  entryPartCount: number,
): void {
  if (foundation.parts !== entryPartCount + 1) {
    throw new InvalidBackupArchiveError('The foundation part count does not match the archive.');
  }
}

function buildArchive(
  reader: ZipReader<Blob>,
  foundation: FileEntry,
  entryParts: readonly FileEntry[],
): BackupArchive {
  return {
    entryPartCount: entryParts.length,
    foundation: () => extractMember(foundation),
    entryPart: (index) => extractMember(requireEntryPart(entryParts, index)),
    close: () => reader.close(),
  };
}

function requireEntryPart(entryParts: readonly FileEntry[], index: number): FileEntry {
  const entry = entryParts[index - 1];
  if (!entry) {
    throw new RangeError(`No entry part at index ${index}.`);
  }
  return entry;
}

function extractMember(entry: FileEntry): Promise<Blob> {
  return entry.getData(new BlobWriter('application/gzip'));
}
