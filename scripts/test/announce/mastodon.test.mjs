import { test } from 'node:test';
import assert from 'node:assert/strict';
import { postMastodonThread } from '../../announce/mastodon.mjs';
import { fakeFetch } from './fake-fetch.mjs';

test('posts each status as a reply to the one before', async () => {
  const { fetch, calls } = fakeFetch([
    [200, { id: '1', url: 'https://mastodon.example/@lars/1' }],
    [200, { id: '2', url: 'https://mastodon.example/@lars/2' }],
  ]);

  const url = await postMastodonThread(['root', 'reply'], { instance: 'https://mastodon.example/', token: 'secret', fetch });

  assert.equal(url, 'https://mastodon.example/@lars/1');
  assert.equal(calls[0].url, 'https://mastodon.example/api/v1/statuses');
  assert.equal(calls[0].init.headers.Authorization, 'Bearer secret');
  assert.deepEqual(calls[0].body, { status: 'root', visibility: 'public', language: 'en' });
  assert.deepEqual(calls[1].body, { status: 'reply', visibility: 'public', language: 'en', in_reply_to_id: '1' });
});

test('sends a stable idempotency key per post', async () => {
  const first = fakeFetch([[200, { id: '1', url: 'u' }]]);
  const second = fakeFetch([[200, { id: '1', url: 'u' }]]);

  await postMastodonThread(['root'], { instance: 'https://m.example', token: 't', fetch: first.fetch });
  await postMastodonThread(['root'], { instance: 'https://m.example', token: 't', fetch: second.fetch });

  const key = first.calls[0].init.headers['Idempotency-Key'];
  assert.match(key, /^[0-9a-f]{64}$/);
  assert.equal(second.calls[0].init.headers['Idempotency-Key'], key);
});

test('a refused post throws with its number and status', async () => {
  const { fetch } = fakeFetch([
    [200, { id: '1', url: 'u' }],
    [422, { error: 'Validation failed' }],
  ]);

  await assert.rejects(
    postMastodonThread(['root', 'reply'], { instance: 'https://m.example', token: 't', fetch }),
    /Mastodon refused post 2: HTTP 422/,
  );
});
