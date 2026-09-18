import { InvalidBackupArchiveError } from './backup-archive';

export interface BackupPartHeader {
  backupId: string;
  part: number;
  parts: number | null;
}

export type ReadPartHeader = (member: Blob) => Promise<BackupPartHeader>;

export const readPartHeader: ReadPartHeader = async (member) => {
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
  const header = JSON.parse(text.split('\n', 1)[0]) as Partial<BackupPartHeader> & {
    kind?: string;
  };
  if (!isReadableHeader(header)) {
    throw new InvalidBackupArchiveError('The part has no readable header.');
  }
  return { backupId: header.backupId, part: header.part, parts: header.parts ?? null };
};

function isReadableHeader(
  header: Partial<BackupPartHeader> & { kind?: string },
): header is { kind: string; backupId: string; part: number; parts: number | null } {
  return (
    header.kind === 'header' &&
    typeof header.backupId === 'string' &&
    typeof header.part === 'number'
  );
}
