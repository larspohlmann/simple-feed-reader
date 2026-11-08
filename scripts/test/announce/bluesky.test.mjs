import { test } from 'node:test';
import assert from 'node:assert/strict';
import { extractHighlights } from '../../announce/thread.mjs';
import { postBlueskyThread, richTextFacets } from '../../announce/bluesky.mjs';

function fakeFetch(responses) {
  const calls = [];
  const fetch = async (url, init) => {
    calls.push({ url, init, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body });
    const [status, payload] = responses.shift();
    return new Response(JSON.stringify(payload), { status });
  };
  return { fetch, calls };
}

const card = { uri: 'https://lars-pohlmann.de/simplefeedreader/', title: 'simple-feed-reader', description: 'A reader.', thumb: null };

test('richTextFacets marks URLs by UTF-8 byte offsets without trailing punctuation', () => {
  assert.deepEqual(richTextFacets('Größe: https://example.org/a.'), [
    {
      index: { byteStart: 9, byteEnd: 30 },
      features: [{ $type: 'app.bsky.richtext.facet#link', uri: 'https://example.org/a' }],
    },
  ]);
});

test('richTextFacets marks hashtags, in text order with links', () => {
  assert.deepEqual(richTextFacets('Neu: #rss #rssreader\n\nhttps://x.org/'), [
    { index: { byteStart: 5, byteEnd: 9 }, features: [{ $type: 'app.bsky.richtext.facet#tag', tag: 'rss' }] },
    { index: { byteStart: 10, byteEnd: 20 }, features: [{ $type: 'app.bsky.richtext.facet#tag', tag: 'rssreader' }] },
    { index: { byteStart: 22, byteEnd: 36 }, features: [{ $type: 'app.bsky.richtext.facet#link', uri: 'https://x.org/' }] },
  ]);
});

test('richTextFacets reads a fragment inside a URL as part of the link, not as a tag', () => {
  assert.deepEqual(
    richTextFacets('see https://x.org/a#b').map((facet) => facet.features[0].$type),
    ['app.bsky.richtext.facet#link'],
  );
});

test('richTextFacets finds nothing in plain text', () => {
  assert.deepEqual(richTextFacets('no links here'), []);
});

test('logs in, then threads each post under the root and the post before', async () => {
  const { fetch, calls } = fakeFetch([
    [200, { accessJwt: 'jwt', did: 'did:plc:lars' }],
    [200, { uri: 'at://did:plc:lars/app.bsky.feed.post/root1', cid: 'c1' }],
    [200, { uri: 'at://did:plc:lars/app.bsky.feed.post/reply2', cid: 'c2' }],
    [200, { uri: 'at://did:plc:lars/app.bsky.feed.post/reply3', cid: 'c3' }],
  ]);

  const url = await postBlueskyThread(['root #rss https://lars-pohlmann.de/simplefeedreader/', 'two', 'three'], {
    service: 'https://pds.example/', handle: 'lars.example', appPassword: 'app-pw', card, fetch,
  });

  assert.equal(url, 'https://bsky.app/profile/did:plc:lars/post/root1');
  assert.equal(calls.length, 4);
  assert.equal(calls[0].url, 'https://pds.example/xrpc/com.atproto.server.createSession');
  assert.deepEqual(calls[0].body, { identifier: 'lars.example', password: 'app-pw' });
  assert.equal(calls[1].url, 'https://pds.example/xrpc/com.atproto.repo.createRecord');
  assert.equal(calls[1].init.headers.Authorization, 'Bearer jwt');
  assert.equal(calls[1].body.repo, 'did:plc:lars');
  assert.equal(calls[1].body.collection, 'app.bsky.feed.post');

  const root = calls[1].body.record;
  assert.equal(root.$type, 'app.bsky.feed.post');
  assert.equal(root.text, 'root #rss https://lars-pohlmann.de/simplefeedreader/');
  assert.deepEqual(root.langs, ['en']);
  assert.equal(root.facets.length, 2);
  assert.deepEqual(root.embed, {
    $type: 'app.bsky.embed.external',
    external: { uri: card.uri, title: card.title, description: card.description },
  });
  assert.equal(root.reply, undefined);

  const third = calls[3].body.record;
  assert.equal(third.embed, undefined);
  assert.deepEqual(third.reply, {
    root: { uri: 'at://did:plc:lars/app.bsky.feed.post/root1', cid: 'c1' },
    parent: { uri: 'at://did:plc:lars/app.bsky.feed.post/reply2', cid: 'c2' },
  });
});

test('uploads the card image and puts the blob on the root card', async () => {
  const bytes = new Uint8Array([137, 80, 78, 71]);
  const blob = { $type: 'blob', ref: { $link: 'bafkrei' }, mimeType: 'image/png', size: 4 };
  const { fetch, calls } = fakeFetch([
    [200, { accessJwt: 'jwt', did: 'did:plc:lars' }],
    [200, { blob }],
    [200, { uri: 'at://did:plc:lars/app.bsky.feed.post/root1', cid: 'c1' }],
  ]);

  await postBlueskyThread(['root'], {
    service: 'https://pds.example', handle: 'h', appPassword: 'p', card: { ...card, thumb: { bytes, mimeType: 'image/png' } }, fetch,
  });

  assert.equal(calls[1].url, 'https://pds.example/xrpc/com.atproto.repo.uploadBlob');
  assert.equal(calls[1].init.headers['Content-Type'], 'image/png');
  assert.equal(calls[1].init.headers.Authorization, 'Bearer jwt');
  assert.equal(calls[1].body, bytes);
  assert.deepEqual(calls[2].body.record.embed.external.thumb, blob);
});

test('a failed login throws naming the login', async () => {
  const { fetch } = fakeFetch([[401, { error: 'AuthenticationRequired' }]]);

  await assert.rejects(
    postBlueskyThread(['root'], { service: 'https://pds.example', handle: 'h', appPassword: 'p', card, fetch }),
    /Bluesky login failed: HTTP 401/,
  );
});

test('a refused post throws with its number and status', async () => {
  const { fetch } = fakeFetch([
    [200, { accessJwt: 'jwt', did: 'did:plc:lars' }],
    [400, { error: 'InvalidRequest' }],
  ]);

  await assert.rejects(
    postBlueskyThread(['root'], { service: 'https://pds.example', handle: 'h', appPassword: 'p', card, fetch }),
    /Bluesky refused post 1: HTTP 400/,
  );
});

const linkOf = (text) => richTextFacets(text).find((found) => found.features[0].uri).features[0].uri;

test('richTextFacets leaves a closing parenthesis out of the link', () => {
  assert.equal(linkOf('(see https://x.org/a)'), 'https://x.org/a');
  assert.equal(linkOf('(https://x.org/a).'), 'https://x.org/a');
});

test('richTextFacets keeps the parenthesis of a balanced address', () => {
  assert.equal(linkOf('See https://en.wikipedia.org/wiki/Foo_(bar)'), 'https://en.wikipedia.org/wiki/Foo_(bar)');
});

test('richTextFacets leaves a closing quote out of the link', () => {
  assert.equal(linkOf('"https://q.org/b"'), 'https://q.org/b');
});

test('a markdown link in a highlight becomes a facet over exactly its address', () => {
  const [text] = extractHighlights('### Highlights\n\n**X.** See [the docs](https://example.org/a).\n');
  const { index, features } = richTextFacets(text).find((found) => found.features[0].uri);
  const covered = Buffer.from(text).subarray(index.byteStart, index.byteEnd).toString();
  assert.equal(features[0].uri, 'https://example.org/a');
  assert.equal(covered, 'https://example.org/a');
});
