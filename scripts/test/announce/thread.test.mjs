import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildThread, extractHighlights, graphemeLength, splitToFit } from '../../announce/thread.mjs';

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

test('extractHighlights returns one plain-text entry per paragraph', () => {
  assert.deepEqual(extractHighlights(releaseBody), [
    'Social searches as feeds. You can now subscribe to a Bluesky or Mastodon search, and it shows up as a feed of its own.',
    'Trending. See the docs (https://example.org/trending) and npm ci.',
  ]);
});

test('extractHighlights treats each list item as a highlight of its own', () => {
  const body = '### Highlights\n\n- **One.** First.\n- **Two.** Second.\n';
  assert.deepEqual(extractHighlights(body), ['One. First.', 'Two. Second.']);
});

test('extractHighlights returns nothing when the body has no highlights', () => {
  assert.deepEqual(extractHighlights('## What\'s Changed\n* fix(#1): x\n'), []);
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

test('buildThread leads with the homepage and tags, and closes with the release notes', () => {
  const thread = buildThread({
    tag: 'v1.9.0',
    releaseUrl: 'https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.9.0',
    highlights: ['One. First.', 'Two. Second.'],
    limit: 300,
  });
  assert.deepEqual(thread, [
    'simple-feed-reader v1.9.0 is out. The highlights follow in this thread. #rss #rssreader\n\nhttps://lars-pohlmann.de/simplefeedreader/',
    'One. First.',
    'Two. Second.',
    'Full release notes: https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.9.0',
  ]);
});
