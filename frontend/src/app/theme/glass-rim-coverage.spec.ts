import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const SOURCE_ROOT = join(__dirname, '..', '..');
const GUARDED = [
  'app/theme',
  'styles',
  'app/shared',
  'app/reader/shell',
  'app/reader/feeds',
  'app/reader/entry',
  'app/reader/list',
];
const UNGUARDED = ['app/admin', 'app/settings/admin'];
const FULL_BORDER = /^\s*border\s*:\s*(\d+px\s+solid\b|var\(--card-border\))/;

interface Exemption {
  file: string;
  selector: string;
  reason: string;
}

interface Site {
  file: string;
  line: number;
  selector: string;
  declaration: string;
}

const EXEMPT: readonly Exemption[] = [
  {
    file: 'app/theme/_glass-rim.scss',
    selector: '@mixin filled($fill, $base: var(--border), $tier: surface)',
    reason: 'the rim itself: its gradient paints this transparent border',
  },
  {
    file: 'app/theme/_glass-rim.scss',
    selector: '@mixin ring($base: var(--border), $tier: surface)',
    reason: 'the rim itself: its ::after paints over this transparent border',
  },
  {
    file: 'app/shared/color-field/color-field.component.scss',
    selector: '.swatch',
    reason: 'a 2px selection ring around a user colour, not chrome',
  },
  {
    file: 'app/reader/shell/sidebar/sidebar.component.scss',
    selector: '.section-chevron app-icon',
    reason: 'a transparent border that only matches the .chevzone glyph box metrics',
  },
  {
    file: 'app/reader/feeds/add-feed/add-feed-dialog.component.scss',
    selector: '.subscribe',
    reason: 'a solid accent button: its border is its fill colour, flat like primary',
  },
];

function scssFiles(directory: string): string[] {
  return readdirSync(join(SOURCE_ROOT, directory), { withFileTypes: true }).flatMap((entry) => {
    const path = directory === '.' ? entry.name : `${directory}/${entry.name}`;
    if (entry.isDirectory()) return UNGUARDED.includes(path) ? [] : scssFiles(path);
    return entry.name.endsWith('.scss') ? [path] : [];
  });
}

/** Blanks comments and `#{…}` so neither can fake a declaration or unbalance the braces. */
function withoutComments(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, (comment) => comment.replace(/[^\n]/g, ' '))
    .replace(/(^|\s)\/\/.*$/gm, '$1')
    .replace(/#\{[^}]*\}/g, '#()');
}

function fullBorders(file: string): Site[] {
  const sites: Site[] = [];
  const openers: string[] = [];
  let pending = '';
  withoutComments(readFileSync(join(SOURCE_ROOT, file), 'utf8'))
    .split('\n')
    .forEach((text, index) => {
      if (FULL_BORDER.test(text)) {
        const selector = openers.at(-1) ?? '';
        sites.push({ file, line: index + 1, selector, declaration: text.trim() });
      }
      for (const character of text) {
        if (character === '{') {
          openers.push(pending.replace(/\s+/g, ' ').trim());
          pending = '';
        } else if (character === '}') {
          openers.pop();
          pending = '';
        } else if (character === ';') {
          pending = '';
        } else {
          pending += character;
        }
      }
      pending += ' ';
    });
  return sites;
}

const SITES = GUARDED.flatMap((directory) => scssFiles(directory)).flatMap((file) =>
  fullBorders(file),
);

const covers = (exemption: Exemption, site: Site): boolean =>
  exemption.file === site.file && exemption.selector === site.selector;

describe('glass rim coverage', () => {
  it('leaves no full border outside the glass-rim mixins', () => {
    const bare = SITES.filter((site) => !EXEMPT.some((exemption) => covers(exemption, site)));
    expect(
      bare.map(
        ({ file, line, selector, declaration }) => `${file}:${line} [${selector}] ${declaration}`,
      ),
    ).toEqual([]);
  });

  it.each(EXEMPT)('still needs its exemption for $file [$selector]', (exemption) => {
    expect(SITES.some((site) => covers(exemption, site))).toBe(true);
  });
});
