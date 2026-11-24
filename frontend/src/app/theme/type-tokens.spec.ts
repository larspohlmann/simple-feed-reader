import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { compileScssFile, cssRules } from './sass-testing';

const CLASSIC = ':root[data-theme-name=classic]';

const CLASSIC_TYPE = {
  '--font-text': '"Newsreader Classic", georgia, serif',
  '--title-weight': '600',
  '--title-tracking': '-0.006em',
  '--title-lh': '1.2',
  '--summary-lh': '1.45',
  '--read-lh': '1.62',
  '--read-heading-weight': '650',
  '--app-name-weight': '700',
  '--app-name-size': '1.2em',
  '--count-numerals': 'lining-nums tabular-nums',
};

const THEME_ONLY = Object.keys(CLASSIC_TYPE).filter((token) => token !== '--font-text');

const SRC = join(__dirname, '..', '..');

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    return entry.isDirectory() ? sourceFiles(path) : [path];
  });
}

describe('classic type tokens', () => {
  const rules = cssRules(compileScssFile(join(__dirname, 'tokens.scss')));
  const classic = rules.filter((rule) => rule.selector === CLASSIC);

  it('declares the type in one mode-independent classic block', () => {
    expect(classic).toHaveLength(1);
  });

  it.each(Object.entries(CLASSIC_TYPE))('sets %s to %s under classic', (token, value) => {
    expect(classic[0]?.declarations[token]).toBe(value);
  });

  it.each(THEME_ONLY)('declares %s nowhere else in the tokens', (token) => {
    const elsewhere = rules.filter(
      (rule) => rule.selector !== CLASSIC && token in rule.declarations,
    );
    expect(elsewhere.map((rule) => rule.selector)).toEqual([]);
  });

  it.each(THEME_ONLY)('declares %s in no other stylesheet', (token) => {
    const declaring = sourceFiles(SRC)
      .filter((path) => path.endsWith('.scss') && !path.endsWith(join('theme', 'tokens.scss')))
      .filter((path) => new RegExp(`${token}\\s*:`).test(readFileSync(path, 'utf8')));
    expect(declaring).toEqual([]);
  });

  it('leaves no mention of the old family token under src', () => {
    const mentioning = sourceFiles(SRC).filter((path) =>
      readFileSync(path, 'utf8').includes('--font-' + 'sans'),
    );
    expect(mentioning).toEqual([]);
  });
});
