import en from '../../../public/i18n/en.json';
import de from '../../../public/i18n/de.json';

/**
 * A key missing from one dictionary fails no build or lint -- it renders the
 * raw key on screen, in the one language nobody testing the change looks at.
 * Comparing the two key sets is the only thing that catches it.
 */
const flatten = (node: unknown, prefix = ''): string[] => {
  if (typeof node !== 'object' || node === null) {
    return [prefix];
  }

  return Object.entries(node).flatMap(([key, value]) =>
    flatten(value, prefix ? `${prefix}.${key}` : key),
  );
};

describe('i18n dictionaries', () => {
  it('define the same keys in every language', () => {
    const english = flatten(en).sort();
    const german = flatten(de).sort();

    expect(german.filter((key) => !english.includes(key))).toEqual([]);
    expect(english.filter((key) => !german.includes(key))).toEqual([]);
  });

  it('leave no value empty', () => {
    const blank = (dictionary: unknown, lang: string): string[] =>
      flatten(dictionary)
        .filter((key) => {
          const value = key
            .split('.')
            .reduce<unknown>((node, part) => (node as Record<string, unknown>)[part], dictionary);

          return typeof value !== 'string' || value.trim() === '';
        })
        .map((key) => `${lang}:${key}`);

    expect([...blank(en, 'en'), ...blank(de, 'de')]).toEqual([]);
  });
});
