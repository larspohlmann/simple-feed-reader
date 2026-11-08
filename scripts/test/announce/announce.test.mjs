import { test } from 'node:test';
import assert from 'node:assert/strict';
import { announce } from '../../announce/announce.mjs';

const releaseBody = '### Highlights\n\n**One.** First.\n\n## What\'s Changed\n';
const base = { tag: 'v1.9.0', releaseUrl: 'https://example.org/release', releaseBody, cardImage: null, dryRun: false };
const mastodonEnv = { MASTODON_INSTANCE: 'https://m.example', MASTODON_TOKEN: 't' };
const blueskyEnv = { BLUESKY_HANDLE: 'h', BLUESKY_APP_PASSWORD: 'p' };

function recorder() {
  const lines = [];
  return { lines, log: (line) => lines.push(line) };
}

function respondingFetch({ failingHost = null } = {}) {
  const urls = [];
  const fetch = async (url) => {
    urls.push(url);
    if (failingHost !== null && url.startsWith(failingHost)) {
      return new Response('{"error":"down"}', { status: 503 });
    }
    const payload = url.includes('createSession')
      ? { accessJwt: 'jwt', did: 'did:plc:x' }
      : { id: '1', url: 'https://m.example/@l/1', uri: 'at://did:plc:x/app.bsky.feed.post/r', cid: 'c' };
    return new Response(JSON.stringify(payload), { status: 200 });
  };
  return { fetch, urls };
}

test('a release without highlights posts nothing and succeeds', async () => {
  const { lines, log } = recorder();
  const { fetch, urls } = respondingFetch();

  const code = await announce({ ...base, releaseBody: '## What\'s Changed\n', env: { ...mastodonEnv, ...blueskyEnv }, fetch, log });

  assert.equal(code, 0);
  assert.deepEqual(urls, []);
  assert.deepEqual(lines, ['v1.9.0 has no highlights; nothing to announce.']);
});

test('posts to every configured network', async () => {
  const { log } = recorder();
  const { fetch, urls } = respondingFetch();

  const code = await announce({ ...base, env: { ...mastodonEnv, ...blueskyEnv }, fetch, log });

  assert.equal(code, 0);
  assert.equal(urls.filter((url) => url.endsWith('/api/v1/statuses')).length, 3);
  assert.equal(urls.filter((url) => url.endsWith('.createRecord')).length, 3);
  assert.ok(urls.includes('https://bsky.social/xrpc/com.atproto.server.createSession'));
});

test('a network without secrets is skipped with a warning', async () => {
  const { lines, log } = recorder();
  const { fetch, urls } = respondingFetch();

  const code = await announce({ ...base, env: mastodonEnv, fetch, log });

  assert.equal(code, 0);
  assert.ok(lines.includes('::warning::Bluesky secrets are not set; skipping.'));
  assert.ok(urls.every((url) => url.startsWith('https://m.example')));
});

test('a failing network fails the run but the other still posts', async () => {
  const { lines, log } = recorder();
  const { fetch, urls } = respondingFetch({ failingHost: 'https://m.example' });

  const code = await announce({ ...base, env: { ...mastodonEnv, ...blueskyEnv }, fetch, log });

  assert.equal(code, 1);
  assert.ok(lines.some((line) => line.startsWith('::error::Mastodon: Mastodon refused post 1: HTTP 503')));
  assert.equal(urls.filter((url) => url.endsWith('.createRecord')).length, 3);
});

test('a dry run prints both threads and calls nothing', async () => {
  const { lines, log } = recorder();
  const { fetch, urls } = respondingFetch();

  const code = await announce({ ...base, env: {}, fetch, log, dryRun: true });

  assert.equal(code, 0);
  assert.deepEqual(urls, []);
  const thread = [
    '[1] simple-feed-reader v1.9.0 is out. The highlights follow in this thread. #rss #rssreader\n\nhttps://lars-pohlmann.de/simplefeedreader/',
    '[2] One. First.',
    '[3] Full release notes: https://example.org/release',
  ];
  assert.deepEqual(lines, ['--- Mastodon (3 posts)', ...thread, '--- Bluesky (3 posts)', ...thread]);
});
