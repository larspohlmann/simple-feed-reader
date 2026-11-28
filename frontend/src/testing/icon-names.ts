import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { listFiles } from './list-files';

const GENERATED_TABLE = 'app/shared/icon/icon-ink-shifts.ts';
const QUOTED_NAME = /['"`]([a-z0-9_]+)['"`]/g;
const GLYPH_NAMES_PATH = ['scripts', 'icons', 'glyph-names.txt'];
export const ICON_VARIATIONS = {
  outlined: "'opsz' 20",
  filled: "'opsz' 20, 'FILL' 1",
} as const;

const HEADER = /^# material-symbols (\S+)$/;

function readGlyphNameLines(frontendRoot: string): string[] {
  return readFileSync(join(frontendRoot, ...GLYPH_NAMES_PATH), 'utf8').split('\n');
}

export function glyphNamesFontVersion(frontendRoot: string): string {
  return HEADER.exec(readGlyphNameLines(frontendRoot)[0])?.[1] ?? '';
}

export function fontGlyphNames(frontendRoot: string): ReadonlySet<string> {
  return new Set(readGlyphNameLines(frontendRoot).filter((line) => line && !line.startsWith('#')));
}

export function iconSourceFiles(frontendRoot: string): string[] {
  return listFiles(join(frontendRoot, 'src'), (path) => {
    if (path.startsWith('testing/') || path === GENERATED_TABLE) {
      return false;
    }
    return (path.endsWith('.ts') && !path.endsWith('.spec.ts')) || path.endsWith('.html');
  });
}

export function scanIconNames(frontendRoot: string): string[] {
  const font = fontGlyphNames(frontendRoot);
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

export function installedFontVersion(frontendRoot: string): string {
  const manifest = JSON.parse(
    readFileSync(join(frontendRoot, 'node_modules', 'material-symbols', 'package.json'), 'utf8'),
  ) as { version: string };
  return manifest.version;
}
