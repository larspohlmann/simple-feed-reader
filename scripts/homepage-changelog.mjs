#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'node:fs';
import { renderChangelogPage } from './homepage-changelog/changelog.mjs';

const [changelogPath, outputPath] = process.argv.slice(2);
if (!changelogPath || !outputPath) {
  console.error('usage: homepage-changelog.mjs <CHANGELOG.md> <out.html>');
  process.exit(2);
}

const template = readFileSync(new URL('./homepage-changelog/page.html', import.meta.url), 'utf8');
writeFileSync(outputPath, renderChangelogPage(readFileSync(changelogPath, 'utf8'), template));
