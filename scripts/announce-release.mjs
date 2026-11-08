#!/usr/bin/env node
// scripts/announce-release.mjs
//   gh release view v1.9.0 --json body --jq .body \
//     | node scripts/announce-release.mjs v1.9.0 <release-url> [--dry-run]
import { readFileSync } from 'node:fs';
import { announce } from './announce/announce.mjs';

const [tag, releaseUrl, flag] = process.argv.slice(2);
if (!tag || !releaseUrl || (flag !== undefined && flag !== '--dry-run')) {
  console.error('usage: node scripts/announce-release.mjs <tag> <release-url> [--dry-run] < release-body.md');
  process.exit(2);
}

process.exitCode = await announce({
  tag,
  releaseUrl,
  releaseBody: readFileSync(0, 'utf8'),
  cardImage: { bytes: readFileSync(new URL('../docs/homepage/share.png', import.meta.url)), mimeType: 'image/png' },
  env: process.env,
  fetch: globalThis.fetch,
  log: console.log,
  dryRun: flag === '--dry-run',
});
