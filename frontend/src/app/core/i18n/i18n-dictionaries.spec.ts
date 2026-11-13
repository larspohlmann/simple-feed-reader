import en from '../../../../public/i18n/en.json';
import de from '../../../../public/i18n/de.json';

const dictionaries: Record<string, unknown> = { en, de };

/**
 * A key missing from one dictionary fails no build or lint -- it renders the
 * raw key on screen, in the one language nobody testing the change looks at.
 * Comparing every language against English is the only thing that catches it.
 */
const flatten = (node: unknown, prefix = ''): Map<string, unknown> => {
  if (typeof node !== 'object' || node === null) {
    return new Map([[prefix, node]]);
  }

  return new Map(
    Object.entries(node).flatMap(([key, value]) => [
      ...flatten(value, prefix ? `${prefix}.${key}` : key),
    ]),
  );
};

const placeholders = (value: unknown): string[] =>
  typeof value === 'string'
    ? [...value.matchAll(/\{\{\s*([^}\s]+)\s*\}\}/g)].map((match) => match[1])
    : [];

const translations = Object.entries(dictionaries).filter(([lang]) => lang !== 'en');
const english = flatten(en);

describe('i18n dictionaries', () => {
  it('define the same keys in every language', () => {
    const mismatched = translations.flatMap(([lang, dictionary]) => {
      const keys = flatten(dictionary);

      return [
        ...[...keys.keys()].filter((key) => !english.has(key)).map((key) => `${lang}:${key}`),
        ...[...english.keys()].filter((key) => !keys.has(key)).map((key) => `${lang}:${key}`),
      ];
    });

    expect(mismatched).toEqual([]);
  });

  it('leave no value empty', () => {
    const blank = Object.entries(dictionaries).flatMap(([lang, dictionary]) =>
      [...flatten(dictionary)]
        .filter(([, value]) => typeof value !== 'string' || value.trim() === '')
        .map(([key]) => `${lang}:${key}`),
    );

    expect(blank).toEqual([]);
  });

  it('use only placeholders the English string has', () => {
    const invented = translations.flatMap(([lang, dictionary]) =>
      [...flatten(dictionary)]
        .filter(([key, value]) => {
          const allowed = placeholders(english.get(key));

          return placeholders(value).some((name) => !allowed.includes(name));
        })
        .map(([key, value]) => `${lang}:${key} ${String(value)}`),
    );

    expect(invented).toEqual([]);
  });
});
