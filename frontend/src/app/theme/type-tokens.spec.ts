import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { listFiles } from '../../testing/list-files';
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
};

const THEME_ONLY = Object.keys(CLASSIC_TYPE).filter((token) => token !== '--font-text');

const SRC = join(__dirname, '..', '..');

describe('classic type tokens', () => {
  const rules = cssRules(compileScssFile(join(__dirname, 'tokens.scss')));
  const classic = rules.filter((rule) => rule.selector === CLASSIC);
  const otherStylesheets = listFiles(
    SRC,
    (path) => path.endsWith('.scss') && path !== 'app/theme/tokens.scss',
  ).map((path) => ({ path, source: readFileSync(join(SRC, path), 'utf8') }));

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
    const declaring = otherStylesheets
      .filter(({ source }) => new RegExp(`${token}\\s*:`).test(source))
      .map(({ path }) => path);
    expect(declaring).toEqual([]);
  });
});
