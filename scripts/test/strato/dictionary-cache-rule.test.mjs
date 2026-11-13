import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const readRepoFile = (relativePath) =>
  readFileSync(fileURLToPath(new URL(`../../../${relativePath}`, import.meta.url)), 'utf8');

function shippedLanguages() {
  const declaration = readRepoFile('frontend/src/app/core/i18n/language.ts').match(
    /export const LANGS[^=]*=\s*\[([^\]]*)\]/,
  );
  assert.ok(declaration, 'language.ts no longer declares LANGS as an array literal');
  return [...declaration[1].matchAll(/'([^']+)'/g)].map((quoted) => quoted[1]);
}

function dictionaryPatterns() {
  const patterns = [...readRepoFile('deploy/strato/.htaccess').matchAll(/<FilesMatch "([^"]*\\\.json\$)">/g)];
  return patterns.map((found) => found[1]);
}

test('the Strato cache rule names exactly the languages the UI ships', () => {
  assert.deepEqual(dictionaryPatterns(), [`^(${shippedLanguages().join('|')})\\.json$`]);
});
