import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  buildThread,
  extractHighlights,
  graphemeLength,
  HOMEPAGE_DESCRIPTION,
  HOMEPAGE_TITLE,
  LEAD_LIMIT,
  splitToFit,
} from '../../announce/thread.mjs';

const homepage = readFileSync(new URL('../../../docs/homepage/index.html', import.meta.url), 'utf8');

function ogContent(property) {
  return homepage.match(new RegExp(`<meta property="${property}" content="([^"]*)">`))[1];
}

test('the card title and description are the homepage og tags', () => {
  assert.equal(HOMEPAGE_TITLE, ogContent('og:title'));
  assert.equal(HOMEPAGE_DESCRIPTION, ogContent('og:description'));
});

const releaseBody = [
  '### Highlights',
  '',
  '**Social searches as feeds.** You can now subscribe to a Bluesky or Mastodon search,',
  'and it shows up as a feed of its own.',
  '',
  '**Trending.** See [the docs](https://example.org/trending) and `npm ci`.',
  '',
  '## What\'s Changed',
  '* fix(#1): something by @larspohlmann',
].join('\r\n');

test('extractHighlights returns one titled plain-text entry per paragraph', () => {
  assert.deepEqual(extractHighlights(releaseBody), {
    lead: null,
    highlights: [
      {
        title: 'Social searches as feeds',
        text: 'Social searches as feeds. You can now subscribe to a Bluesky or Mastodon search, and it shows up as a feed of its own.',
      },
      { title: 'Trending', text: 'Trending. See the docs: https://example.org/trending and npm ci.' },
    ],
  });
});

const titled = [
  { title: 'One', text: 'One. First.' },
  { title: 'Two', text: 'Two. Second.' },
];

test('extractHighlights takes an untitled first paragraph as the lead', () => {
  const body = '### Highlights\n\nThis release adds one and two.\n\n**One.** First.\n\n**Two.** Second.\n';
  assert.deepEqual(extractHighlights(body), { lead: 'This release adds one and two.', highlights: titled });
});

test('extractHighlights keeps a later untitled paragraph as an untitled highlight', () => {
  const body = '### Highlights\n\n**One.** First.\n\nAlso worth knowing.\n';
  assert.deepEqual(extractHighlights(body).highlights, [
    { title: 'One', text: 'One. First.' },
    { title: null, text: 'Also worth knowing.' },
  ]);
});

test('extractHighlights treats each list item as a highlight of its own', () => {
  const body = '### Highlights\n\n- **One.** First.\n- **Two.** Second.\n';
  assert.deepEqual(extractHighlights(body), { lead: null, highlights: titled });
});

test('extractHighlights returns nothing when the body has no highlights', () => {
  assert.deepEqual(extractHighlights('## What\'s Changed\n* fix(#1): x\n'), { lead: null, highlights: [] });
});

test('graphemeLength counts an emoji with modifiers as one', () => {
  assert.equal(graphemeLength('a👍🏽b'), 3);
});

test('splitToFit keeps text that fits in one piece', () => {
  assert.deepEqual(splitToFit('Short enough.', 20), ['Short enough.']);
});

test('splitToFit breaks at sentence boundaries first', () => {
  assert.deepEqual(splitToFit('One two. Three four. Five six.', 20), ['One two. Three four.', 'Five six.']);
});

test('splitToFit breaks an overlong sentence at words', () => {
  assert.deepEqual(splitToFit('aaaa bbbb cccc dddd', 9), ['aaaa bbbb', 'cccc dddd']);
});

test('splitToFit cuts an overlong word by graphemes', () => {
  assert.deepEqual(splitToFit('abcdefghij', 4), ['abcd', 'efgh', 'ij']);
});

test('splitToFit never returns a piece over the limit', () => {
  const text = 'Lorem ipsum dolor sit amet. '.repeat(40);
  for (const piece of splitToFit(text, 300)) {
    assert.ok(graphemeLength(piece) <= 300);
  }
});

const releaseUrl = 'https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.9.0';
const tail ='\n\nhttps://lars-pohlmann.de/simplefeedreader/\n\n#RSS #SelfHosted #OpenSource';
const titleList = `New in simple-feed-reader v1.9.0, a self-hosted, open-source RSS reader:\n\n• One\n• Two${tail}`;

test('buildThread leads with the lead sentence, and closes with the release notes', () => {
  const thread = buildThread({ tag: 'v1.9.0', releaseUrl, lead: 'This release adds one and two.', highlights: titled, limit: 300 });
  assert.deepEqual(thread, [
    `simple-feed-reader v1.9.0, a self-hosted, open-source RSS reader, is out. This release adds one and two.${tail}`,
    'One. First.',
    'Two. Second.',
    `Full release notes: ${releaseUrl}`,
  ]);
});

test('buildThread lists the highlight titles when there is no lead', () => {
  const [root] = buildThread({ tag: 'v1.9.0', releaseUrl, lead: null, highlights: titled, limit: 300 });
  assert.equal(root, titleList);
});

test('buildThread lists the titles when the lead does not fit the network', () => {
  const [root] = buildThread({ tag: 'v1.9.0', releaseUrl, lead: 'Long lead. '.repeat(20).trim(), highlights: titled, limit: 300 });
  assert.equal(root, titleList);
});

test('buildThread leaves untitled highlights out of the title list', () => {
  const highlights = [{ title: null, text: 'Untitled.' }, ...titled];
  const thread = buildThread({ tag: 'v1.9.0', releaseUrl, lead: null, highlights, limit: 300 });
  assert.equal(thread[0], titleList);
  assert.equal(thread[1], 'Untitled.');
});

test('buildThread cuts a title list that does not fit and counts the rest', () => {
  const highlights = Array.from({ length: 10 }, (_, index) => ({
    title: `A rather long highlight title number ${index + 1}`,
    text: `Highlight ${index + 1}.`,
  }));
  const [root] = buildThread({ tag: 'v1.9.0', releaseUrl, lead: null, highlights, limit: 300 });
  assert.ok(graphemeLength(root) <= 300, `root is ${graphemeLength(root)} long`);
  const listed = root.split('\n').filter((line) => line.startsWith('• '));
  assert.ok(listed.length > 0 && listed.length < 10, `${listed.length} titles listed`);
  assert.ok(root.endsWith(`\n… and ${10 - listed.length} more below${tail}`));
});

test('buildThread falls back to the bare frame without a lead or titles', () => {
  const [root] = buildThread({ tag: 'v1.9.0', releaseUrl, lead: null, highlights: [{ title: null, text: 'Plain.' }], limit: 300 });
  assert.equal(root, `simple-feed-reader v1.9.0, a self-hosted, open-source RSS reader, is out.${tail}`);
});

test('a lead of LEAD_LIMIT graphemes fits Bluesky even for a long tag', () => {
  const [root] = buildThread({ tag: 'v1.10.12', releaseUrl, lead: 'x'.repeat(LEAD_LIMIT), highlights: [], limit: 300 });
  assert.ok(root.includes('x'.repeat(LEAD_LIMIT)));
  assert.ok(graphemeLength(root) <= 300, `root is ${graphemeLength(root)} long`);
});
