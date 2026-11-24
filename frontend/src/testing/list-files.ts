import { readdirSync } from 'node:fs';
import { join } from 'node:path';

/** Every file below `root` that `accept` takes, as a `/`-separated path relative to `root`. */
export function listFiles(root: string, accept: (path: string) => boolean): string[] {
  return readdirSync(root, { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile())
    .map((entry) => join(entry.parentPath, entry.name).slice(root.length + 1))
    .filter(accept);
}
