import { InvalidBackupArchiveError } from './backup-archive-error';

export interface BackupPartHeader {
  backupId: string;
  part: number;
  parts: number | null;
}

export type ReadPartHeader = (member: Blob) => Promise<BackupPartHeader>;

export const readPartHeader: ReadPartHeader = async (member) => {
  // A missing DecompressionStream, a non-gzip member or malformed JSON throws
  // a raw TypeError/SyntaxError here; wrap the lot so the archive layer only
  // ever sees InvalidBackupArchiveError, never an untranslated stray error.
  try {
    return parseHeader(await firstLine(member));
  } catch (error) {
    if (error instanceof InvalidBackupArchiveError) throw error;
    throw new InvalidBackupArchiveError('The part header could not be read.');
  }
};

async function firstLine(member: Blob): Promise<string> {
  const reader = member
    .stream()
    .pipeThrough(new DecompressionStream('gzip'))
    .pipeThrough(new TextDecoderStream())
    .getReader();
  let text = '';
  try {
    while (!text.includes('\n')) {
      const { value, done } = await reader.read();
      if (done) break;
      text += value;
    }
  } finally {
    await reader.cancel();
  }
  return text.split('\n', 1)[0];
}

function parseHeader(line: string): BackupPartHeader {
  const header = JSON.parse(line) as Partial<BackupPartHeader> & { kind?: string };
  if (!isReadableHeader(header)) {
    throw new InvalidBackupArchiveError('The part has no readable header.');
  }
  return { backupId: header.backupId, part: header.part, parts: header.parts ?? null };
}

function isReadableHeader(
  header: Partial<BackupPartHeader> & { kind?: string },
): header is { kind: string; backupId: string; part: number; parts: number | null } {
  return (
    header.kind === 'header' &&
    typeof header.backupId === 'string' &&
    typeof header.part === 'number'
  );
}
