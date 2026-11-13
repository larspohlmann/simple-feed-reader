import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseReleases, renderChangelogPage, renderReleasesHtml } from '../../homepage-changelog/changelog.mjs';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

const changelog = [
  '# Changelog',
  '',
  'Intro paragraph.',
  '',
  '## [Unreleased]',
  '',
  '### Highlights',
  '',
  '**Not yet.** Unreleased work.',
  '',
  '## [v1.1.0] - 2026-02-01',
  '',
  '### Highlights',
  '',
  '**New.** A highlight.',
  '',
  "## What's Changed",
  '* feat(#1): a by @larspohlmann in https://github.com/o/r/pull/2',
  '',
  '## [v1.0.0] - 2026-01-01',
  '',
].join('\n');

test('parses versions and dates in file order', () => {
  assert.deepEqual(
    parseReleases(changelog).map(({ version, date }) => [version, date]),
    [['v1.1.0', '2026-02-01'], ['v1.0.0', '2026-01-01']],
  );
});

test('skips Unreleased even when it has content', () => {
  const html = renderReleasesHtml(parseReleases(changelog));
  assert.doesNotMatch(html, /Unreleased|Not yet/);
});

test('splits highlights from the rest', () => {
  const [release] = parseReleases(changelog);
  assert.equal(release.highlights.trim(), '**New.** A highlight.');
  assert.match(release.changes.trim(), /^## What's Changed\n\* feat/);
});

test('links each version to its GitHub Release', () => {
  const html = renderReleasesHtml(parseReleases(changelog));
  assert.match(html, /<a href="https:\/\/github\.com\/larspohlmann\/simple-feed-reader\/releases\/tag\/v1\.1\.0">v1\.1\.0<\/a>/);
  assert.match(html, /<time datetime="2026-02-01">/);
});

test('omits the details element for an empty release', () => {
  const html = renderReleasesHtml(parseReleases(changelog).slice(1));
  assert.doesNotMatch(html, /<details/);
  assert.doesNotMatch(html, /class="highlights"/);
});

test('fills the template once and refuses one without a placeholder', () => {
  assert.equal(renderChangelogPage(changelog, '<main>{{releases}}</main>').includes('{{releases}}'), false);
  assert.throws(() => renderChangelogPage(changelog, '<main></main>'), /\{\{releases\}\}/);
  assert.throws(() => renderChangelogPage('# Changelog\n', '{{releases}}'), /no release/);
});

test('renders the real CHANGELOG.md', () => {
  const markdown = read('../../../CHANGELOG.md');
  const page = renderChangelogPage(markdown, read('../../homepage-changelog/page.html'));
  const versions = [...markdown.matchAll(/^## \[(v[^\]]+)\]/gm)].map((match) => match[1]);
  const highlightCount = markdown.split(/^## \[/m).filter((section) => section.startsWith('v') && /^### Highlights$/m.test(section)).length;
  assert.equal(parseReleases(markdown).length, versions.length);
  assert.equal(parseReleases(markdown)[0].version, versions[0]);
  assert.deepEqual([...page.matchAll(/<section class="release" id="([^"]+)">/g)].map((match) => match[1]), versions);
  assert.equal(page.match(/class="highlights"/g).length, highlightCount);
  assert.doesNotMatch(page, /\{\{releases\}\}/);
});

test('the homepage footer links the changelog page', () => {
  const homepage = read('../../../docs/homepage/index.html');
  assert.match(homepage, /<a href="changelog\.html">Changelog<\/a>/);
  assert.doesNotMatch(homepage, /blob\/develop\/CHANGELOG\.md/);
});
