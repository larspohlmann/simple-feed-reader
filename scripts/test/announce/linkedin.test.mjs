import { test } from 'node:test';
import assert from 'node:assert/strict';
import { escapeLittleText, postLinkedIn } from '../../announce/linkedin.mjs';
import { fakeFetch } from './fake-fetch.mjs';

const author = 'urn:li:person:abc';
const card = { uri: 'https://lars-pohlmann.de/simplefeedreader/', title: 'simple-feed-reader', description: 'A reader.', thumb: null };
const created = [201, null, { 'x-restli-id': 'urn:li:share:42' }];

test('escapeLittleText escapes every reserved character but the hashtag sign', () => {
  assert.equal(
    escapeLittleText('a(b)[c]{d}<e>@f|g~h_i*j\\k #rss'),
    'a\\(b\\)\\[c\\]\\{d\\}\\<e\\>\\@f\\|g\\~h\\_i\\*j\\\\k #rss',
  );
});

test('posts the escaped commentary as an article on the homepage and returns the feed URL', async () => {
  const { fetch, calls } = fakeFetch([created]);

  const url = await postLinkedIn('Out now (v1.9.0). #rss', { token: 'secret', author, card, fetch });

  assert.equal(url, 'https://www.linkedin.com/feed/update/urn:li:share:42/');
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'https://api.linkedin.com/rest/posts');
  assert.equal(calls[0].init.method, 'POST');
  assert.equal(calls[0].init.headers.Authorization, 'Bearer secret');
  assert.equal(calls[0].init.headers['X-Restli-Protocol-Version'], '2.0.0');
  assert.match(calls[0].init.headers['Linkedin-Version'], /^\d{6}$/);
  assert.deepEqual(calls[0].body, {
    author,
    commentary: 'Out now \\(v1.9.0\\). #rss',
    visibility: 'PUBLIC',
    distribution: { feedDistribution: 'MAIN_FEED', targetEntities: [], thirdPartyDistributionChannels: [] },
    content: { article: { source: card.uri, title: card.title, description: card.description } },
    lifecycleState: 'PUBLISHED',
    isReshareDisabledByAuthor: false,
  });
});

test('uploads the card image and puts it on the article as the thumbnail', async () => {
  const bytes = new Uint8Array([137, 80, 78, 71]);
  const { fetch, calls } = fakeFetch([
    [200, { value: { uploadUrl: 'https://www.linkedin.com/dms-uploads/x', image: 'urn:li:image:img1' } }],
    [201, null],
    created,
  ]);

  await postLinkedIn('root', { token: 'secret', author, card: { ...card, thumb: { bytes, mimeType: 'image/png' } }, fetch });

  assert.equal(calls[0].url, 'https://api.linkedin.com/rest/images?action=initializeUpload');
  assert.deepEqual(calls[0].body, { initializeUploadRequest: { owner: author } });
  assert.equal(calls[1].url, 'https://www.linkedin.com/dms-uploads/x');
  assert.equal(calls[1].init.method, 'PUT');
  assert.equal(calls[1].init.headers.Authorization, 'Bearer secret');
  assert.equal(calls[1].init.headers['Content-Type'], 'image/png');
  assert.equal(calls[1].body, bytes);
  assert.equal(calls[2].body.content.article.thumbnail, 'urn:li:image:img1');
});

test('a refused image upload throws naming the step', async () => {
  const { fetch } = fakeFetch([[403, { message: 'forbidden' }]]);

  await assert.rejects(
    postLinkedIn('root', { token: 't', author, card: { ...card, thumb: { bytes: new Uint8Array(), mimeType: 'image/png' } }, fetch }),
    /LinkedIn refused the card image: HTTP 403/,
  );
});

test('a refused post throws with its status', async () => {
  const { fetch } = fakeFetch([[401, { message: 'expired' }]]);

  await assert.rejects(
    postLinkedIn('root', { token: 't', author, card, fetch }),
    /LinkedIn refused the post: HTTP 401/,
  );
});
