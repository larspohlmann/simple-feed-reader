// src/testing/translation-keys.ts
import en from '../../public/i18n/en.json';

/**
 * Does the shipped English dictionary hold this key? Route titles are keys, not
 * finished text, and Transloco answers a missing key with the key itself — so a
 * typo would put `settings.acount.title` in the browser tab. Specs assert the
 * key exists; this is where they look it up.
 */
export function hasTranslation(key: string): boolean {
  const entry = key
    .split('.')
    .reduce<unknown>((node, part) => asRecord(node)?.[part], en as unknown);

  return typeof entry === 'string';
}

function asRecord(node: unknown): Record<string, unknown> | undefined {
  return typeof node === 'object' && node !== null ? (node as Record<string, unknown>) : undefined;
}
