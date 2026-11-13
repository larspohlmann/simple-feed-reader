import en from '../../../../public/i18n/en.json';
import de from '../../../../public/i18n/de.json';
import es from '../../../../public/i18n/es.json';
import fr from '../../../../public/i18n/fr.json';
import { LANGS } from './language';

const dictionaries: Record<string, unknown> = { en, de, fr, es };

/**
 * A key missing from one dictionary fails no build or lint -- it renders the
 * raw key on screen, in the one language nobody testing the change looks at.
 * Comparing every language against English is the only thing that catches it.
 */
const flatten = (node: unknown, prefix = ''): [string, unknown][] => {
  if (typeof node !== 'object' || node === null) {
    return [[prefix, node]];
  }

  return Object.entries(node).flatMap(([key, value]) =>
    flatten(value, prefix ? `${prefix}.${key}` : key),
  );
};

const placeholderNames = (value: unknown): string =>
  [...new Set([...String(value).matchAll(/\{\{\s*([^}\s]+)\s*\}\}/g)].map((match) => match[1]))]
    .sort()
    .join();

const translations = Object.entries(dictionaries).filter(([lang]) => lang !== 'en');
const english = new Map(flatten(en));

describe('i18n dictionaries', () => {
  it('define the same keys in every language', () => {
    const mismatched = translations.flatMap(([lang, dictionary]) => {
      const keys = new Set(flatten(dictionary).map(([key]) => key));

      return [
        ...[...keys].filter((key) => !english.has(key)),
        ...[...english.keys()].filter((key) => !keys.has(key)),
      ].map((key) => `${lang}:${key}`);
    });

    expect(mismatched).toEqual([]);
  });

  it('leave no value empty', () => {
    const blank = Object.entries(dictionaries).flatMap(([lang, dictionary]) =>
      flatten(dictionary)
        .filter(([, value]) => typeof value !== 'string' || value.trim() === '')
        .map(([key]) => `${lang}:${key}`),
    );

    expect(blank).toEqual([]);
  });

  it('keep exactly the placeholders of the English string', () => {
    const differing = translations.flatMap(([lang, dictionary]) =>
      flatten(dictionary)
        .filter(([key, value]) => placeholderNames(value) !== placeholderNames(english.get(key)))
        .map(([key, value]) => `${lang}:${key} ${String(value)}`),
    );

    expect(differing).toEqual([]);
  });

  it('cover every language the app offers', () => {
    expect(Object.keys(dictionaries)).toEqual([...LANGS]);
  });
});
