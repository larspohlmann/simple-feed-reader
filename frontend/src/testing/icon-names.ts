import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { listFiles } from './list-files';

const QUOTED_NAME = /['"`]([a-z0-9_]+)['"`]/g;
const GLYPH_NAMES_PATH = ['scripts', 'icons', 'glyph-names.txt'];
const HEADER = /^# material-symbols (\S+)$/;

export const ICON_VARIATIONS = [
  { name: 'outlined', variation: '"opsz" 20' },
  { name: 'filled', variation: '"opsz" 20, "FILL" 1' },
] as const;

export interface GlyphNames {
  version: string;
  names: ReadonlySet<string>;
}

export function readGlyphNames(frontendRoot: string): GlyphNames {
  const [header, ...lines] = readFileSync(join(frontendRoot, ...GLYPH_NAMES_PATH), 'utf8').split(
    '\n',
  );
  const version = HEADER.exec(header)?.[1];
  if (version === undefined) {
    throw new Error(
      `glyph-names.txt does not start with "# material-symbols <version>". ` +
        'Run `npm run icons:glyph-names` from frontend/.',
    );
  }
  return { version, names: new Set(lines.filter((line) => line && !line.startsWith('#'))) };
}

export function installedFontVersion(frontendRoot: string): string {
  const manifest = JSON.parse(
    readFileSync(join(frontendRoot, 'node_modules', 'material-symbols', 'package.json'), 'utf8'),
  ) as { version: string };
  return manifest.version;
}

export function requireMatchingGlyphNames(frontendRoot: string): void {
  const listed = readGlyphNames(frontendRoot).version;
  const installed = installedFontVersion(frontendRoot);
  if (listed !== installed) {
    throw new Error(
      `glyph-names.txt lists material-symbols ${listed}, but ${installed} is installed. ` +
        'Run `npm run icons:glyph-names` from frontend/, then `npm run icons:measure`.',
    );
  }
}

export function iconSourceFiles(frontendRoot: string): string[] {
  return listFiles(join(frontendRoot, 'src'), (path) => {
    if (path.startsWith('testing/')) {
      return false;
    }
    return (path.endsWith('.ts') && !path.endsWith('.spec.ts')) || path.endsWith('.html');
  });
}

export function scanIconNames(frontendRoot: string): string[] {
  const font = readGlyphNames(frontendRoot).names;
  const names = new Set<string>();
  for (const path of iconSourceFiles(frontendRoot)) {
    const text = readFileSync(join(frontendRoot, 'src', path), 'utf8');
    for (const match of text.matchAll(QUOTED_NAME)) {
      if (font.has(match[1])) {
        names.add(match[1]);
      }
    }
  }
  return [...names].sort();
}
