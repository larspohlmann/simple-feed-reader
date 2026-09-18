/** @jest-environment node */
import { InvalidBackupArchiveError, isOldFormatBackup, openBackupArchive } from './backup-archive';
import type { BackupPartHeader, ReadPartHeader } from './backup-part-header';

// zip.js's own ZipWriter deadlocks under zone.js (`zone.js` patches the global
// Promise in a way that breaks ZipWriter#add's internal watcher bookkeeping —
// reproducible even outside Jest, with plain `zone.js` loaded). The read side
// (ZipReader, what openBackupArchive actually uses) is unaffected. This is a
// minimal store-format zip encoder, used only to build fixtures.
interface ZipMember {
  readonly name: string;
  readonly data: Uint8Array;
}

function crc32(data: Uint8Array): number {
  let crc = ~0;
  for (const byte of data) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) {
      crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
    }
  }
  return ~crc >>> 0;
}

function concatBytes(parts: readonly Uint8Array[]): Uint8Array<ArrayBuffer> {
  const result = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0));
  let position = 0;
  for (const part of parts) {
    result.set(part, position);
    position += part.length;
  }
  return result;
}

function buildLocalFileHeader(nameBytes: Uint8Array, data: Uint8Array, crc: number): Uint8Array {
  const header = new Uint8Array(30 + nameBytes.length + data.length);
  const view = new DataView(header.buffer);
  view.setUint32(0, 0x04034b50, true);
  view.setUint16(4, 20, true);
  view.setUint32(14, crc, true);
  view.setUint32(18, data.length, true);
  view.setUint32(22, data.length, true);
  view.setUint16(26, nameBytes.length, true);
  header.set(nameBytes, 30);
  header.set(data, 30 + nameBytes.length);
  return header;
}

function buildCentralDirectoryEntry(
  nameBytes: Uint8Array,
  dataLength: number,
  crc: number,
  offset: number,
): Uint8Array {
  const entry = new Uint8Array(46 + nameBytes.length);
  const view = new DataView(entry.buffer);
  view.setUint32(0, 0x02014b50, true);
  view.setUint16(4, 20, true);
  view.setUint16(6, 20, true);
  view.setUint32(16, crc, true);
  view.setUint32(20, dataLength, true);
  view.setUint32(24, dataLength, true);
  view.setUint16(28, nameBytes.length, true);
  view.setUint32(42, offset, true);
  entry.set(nameBytes, 46);
  return entry;
}

function buildEndOfCentralDirectory(
  memberCount: number,
  centralSize: number,
  centralOffset: number,
): Uint8Array {
  const eocd = new Uint8Array(22);
  const view = new DataView(eocd.buffer);
  view.setUint32(0, 0x06054b50, true);
  view.setUint16(8, memberCount, true);
  view.setUint16(10, memberCount, true);
  view.setUint32(12, centralSize, true);
  view.setUint32(16, centralOffset, true);
  return eocd;
}

function buildStoredZip(members: readonly ZipMember[]): Uint8Array<ArrayBuffer> {
  const encoder = new TextEncoder();
  const localEntries: Uint8Array[] = [];
  const centralEntries: Uint8Array[] = [];
  let offset = 0;
  for (const member of members) {
    const nameBytes = encoder.encode(member.name);
    const crc = crc32(member.data);
    localEntries.push(buildLocalFileHeader(nameBytes, member.data, crc));
    centralEntries.push(buildCentralDirectoryEntry(nameBytes, member.data.length, crc, offset));
    offset += localEntries[localEntries.length - 1].length;
  }
  const centralOffset = offset;
  const centralSize = centralEntries.reduce((sum, entry) => sum + entry.length, 0);
  const eocd = buildEndOfCentralDirectory(members.length, centralSize, centralOffset);
  return concatBytes([...localEntries, ...centralEntries, eocd]);
}

type MemberHeader = BackupPartHeader & { kind: 'header'; marker?: string };

function buildArchive(members: Record<string, MemberHeader>): Blob {
  const encoder = new TextEncoder();
  const zip = buildStoredZip(
    Object.entries(members).map(([name, header]) => ({
      name,
      data: encoder.encode(JSON.stringify(header)),
    })),
  );
  return new Blob([zip]);
}

function corruptStoredData(archive: Blob, marker: string): Promise<Blob> {
  return archive.arrayBuffer().then((buffer) => {
    const bytes = new Uint8Array(buffer);
    const haystack = Buffer.from(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const index = haystack.indexOf(Buffer.from(marker));
    if (index < 0) {
      throw new Error(`marker "${marker}" not found in archive`);
    }
    bytes[index] ^= 0xff;
    return new Blob([bytes]);
  });
}

const readFakeHeader: ReadPartHeader = async (member) =>
  JSON.parse(await member.text()) as BackupPartHeader;

function header(part: number, overrides: Partial<MemberHeader> = {}): MemberHeader {
  return { kind: 'header', backupId: 'backup-1', part, parts: part === 0 ? 3 : null, ...overrides };
}

describe('openBackupArchive', () => {
  it('opens a valid archive and reads its entry parts', async () => {
    const archive = buildArchive({
      '000-foundation.ndjson.gz': header(0),
      '001-entries.ndjson.gz': header(1),
      '002-entries.ndjson.gz': header(2, { marker: 'second-part' }),
    });

    const opened = await openBackupArchive(archive, readFakeHeader);

    expect(opened.entryPartCount).toBe(2);
    const secondPart = await opened.entryPart(2);
    expect(JSON.parse(await secondPart.text())).toMatchObject({ part: 2, marker: 'second-part' });
    await opened.close();
  });

  it('rejects a file that is not a zip archive', async () => {
    await expect(
      openBackupArchive(new Blob([new Uint8Array([1, 2, 3, 4])]), readFakeHeader),
    ).rejects.toBeInstanceOf(InvalidBackupArchiveError);
  });

  it('rejects an archive missing the foundation part', async () => {
    const archive = buildArchive({
      '001-entries.ndjson.gz': header(1),
      '002-entries.ndjson.gz': header(2),
    });

    await expect(openBackupArchive(archive, readFakeHeader)).rejects.toBeInstanceOf(
      InvalidBackupArchiveError,
    );
  });

  it('rejects an archive with an unexpected member name', async () => {
    const archive = buildArchive({
      '000-foundation.ndjson.gz': header(0),
      'not-a-part.txt': header(1),
    });

    await expect(openBackupArchive(archive, readFakeHeader)).rejects.toBeInstanceOf(
      InvalidBackupArchiveError,
    );
  });

  it('rejects an archive whose entry indexes are not contiguous', async () => {
    const archive = buildArchive({
      '000-foundation.ndjson.gz': header(0, { parts: 3 }),
      '001-entries.ndjson.gz': header(1),
      '003-entries.ndjson.gz': header(3),
    });

    await expect(openBackupArchive(archive, readFakeHeader)).rejects.toBeInstanceOf(
      InvalidBackupArchiveError,
    );
  });

  it('rejects a member that fails the zip signature check', async () => {
    const archive = buildArchive({
      '000-foundation.ndjson.gz': header(0),
      '001-entries.ndjson.gz': header(1, { marker: 'crc-target' }),
      '002-entries.ndjson.gz': header(2),
    });
    const corrupted = await corruptStoredData(archive, 'crc-target');

    await expect(openBackupArchive(corrupted, readFakeHeader)).rejects.toBeInstanceOf(
      InvalidBackupArchiveError,
    );
  });

  it('rejects an archive with a mismatched backup id', async () => {
    const archive = buildArchive({
      '000-foundation.ndjson.gz': header(0),
      '001-entries.ndjson.gz': header(1, { backupId: 'other-backup' }),
      '002-entries.ndjson.gz': header(2),
    });

    await expect(openBackupArchive(archive, readFakeHeader)).rejects.toBeInstanceOf(
      InvalidBackupArchiveError,
    );
  });

  it("rejects an archive whose header part differs from the member name's index", async () => {
    const archive = buildArchive({
      '000-foundation.ndjson.gz': header(0),
      '001-entries.ndjson.gz': header(2),
      '002-entries.ndjson.gz': header(2),
    });

    await expect(openBackupArchive(archive, readFakeHeader)).rejects.toBeInstanceOf(
      InvalidBackupArchiveError,
    );
  });

  it('rejects an archive whose foundation part count does not match', async () => {
    const archive = buildArchive({
      '000-foundation.ndjson.gz': header(0, { parts: 5 }),
      '001-entries.ndjson.gz': header(1),
      '002-entries.ndjson.gz': header(2),
    });

    await expect(openBackupArchive(archive, readFakeHeader)).rejects.toBeInstanceOf(
      InvalidBackupArchiveError,
    );
  });
});

describe('isOldFormatBackup', () => {
  it('recognizes the old single-file gzip backup name', () => {
    expect(isOldFormatBackup('x.json.gz')).toBe(true);
  });

  it('does not recognize a zip archive name', () => {
    expect(isOldFormatBackup('x.zip')).toBe(false);
  });
});
