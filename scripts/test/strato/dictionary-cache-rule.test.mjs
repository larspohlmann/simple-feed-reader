import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const repoPath = (relativePath) => fileURLToPath(new URL(`../../../${relativePath}`, import.meta.url));

function shippedDictionaries() {
  return readdirSync(repoPath('frontend/public/i18n'))
    .filter((file) => file.endsWith('.json'))
    .map((file) => file.slice(0, -'.json'.length))
    .sort();
}

function cachedDictionaries() {
  const rule = readFileSync(repoPath('deploy/strato/.htaccess'), 'utf8').match(
    /<FilesMatch "\^\(([a-z|]+)\)\\\.json\$">/,
  );
  assert.ok(rule, '.htaccess no longer has a FilesMatch rule of the form ^(en|de)\\.json$');
  return rule[1].split('|').sort();
}

test('the Strato cache rule names exactly the dictionaries the UI ships', () => {
  assert.deepEqual(cachedDictionaries(), shippedDictionaries());
});
