# Announce Releases on Bluesky and Mastodon Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a `vX.Y.Z` release carries a `### Highlights` block, post it automatically as a thread to Lars's personal Bluesky and Mastodon accounts.

**Architecture:** A dependency-free Node 22 script (`scripts/announce-release.mjs`) reads a published GitHub Release body on stdin, extracts the highlights, and builds one thread per network: a root post that names the release, links the homepage and carries `#rss #rssreader`, then one reply per highlight (split further where a highlight exceeds the network's limit), then a closing reply linking the GitHub Release. A reusable workflow (`announce-release.yml`) runs it; `release.yml` calls it after the images are tagged, and `workflow_dispatch` runs it by hand for an existing tag.

**Tech Stack:** Node 22 (built-in `fetch`, `Intl.Segmenter`, `node:test`), GitHub Actions, Mastodon REST API, AT Protocol XRPC.

**Spec:** issue [#2096](https://github.com/larspohlmann/simple-feed-reader/issues/2096) and the design agreed in chat: personal accounts, full highlights as a thread, automatic posting with no approval step. The root post links `https://lars-pohlmann.de/simplefeedreader/` and carries `#rss #rssreader`; the release link goes in the thread.

## Global Constraints

- The root post links `https://lars-pohlmann.de/simplefeedreader/` and carries the hashtags `#rss #rssreader`. The GitHub Release link is the last post of the thread, never the root.
- On Bluesky, hashtags need `app.bsky.richtext.facet#tag` facets to be clickable and searchable; Mastodon parses them itself. The Bluesky root carries a homepage link card whose thumbnail is `docs/homepage/share.png` (210 KB, under the 1 MB blob limit), uploaded as a blob.

- No npm dependencies and no third-party GitHub action that sees the credentials. Only `node:` built-ins and global `fetch`.
- Secrets: `MASTODON_INSTANCE` (e.g. `https://mastodon.social`), `MASTODON_TOKEN` (scope `write:statuses` only), `BLUESKY_HANDLE`, `BLUESKY_APP_PASSWORD`, and optional `BLUESKY_SERVICE` (default `https://bsky.social`).
- Limits: Bluesky 300 graphemes per post, Mastodon 500 per post. Both are counted in graphemes.
- A release whose body has no `### Highlights` block posts nothing and succeeds.
- A network whose secrets are missing is skipped with a `::warning::` and does not fail the job. A network that fails does not stop the other one. The job fails if any configured network failed.
- Announcing runs after the `manifests` job, so nothing is posted before the release images exist.
- Commit format `type(#2096): summary`. No Claude attribution.
- Comments: default to none (CLAUDE.md applies to every language in the tree).

## File Structure

| File | Responsibility |
|---|---|
| `scripts/announce/thread.mjs` | Pure text work: extract highlights from a release body, turn Markdown into plain text, split text to fit a limit, build a thread |
| `scripts/announce/mastodon.mjs` | Post a thread to Mastodon |
| `scripts/announce/bluesky.mjs` | Log in, add link and tag facets, post a thread to Bluesky with a homepage card (with thumbnail) on the root |
| `scripts/announce/announce.mjs` | Orchestration: which networks are configured, dry run, error collection, exit code |
| `scripts/announce-release.mjs` | CLI entry: argv + stdin → `announce()` |
| `scripts/test/announce/*.test.mjs` | `node --test` suites, one per module |
| `.github/workflows/announce-release.yml` | Reusable + manually dispatchable workflow |
| `.github/workflows/release.yml` | Calls it after `manifests` |
| `.github/workflows/ci.yml` | Runs the node tests in the `scripts` job |
| `docs/releasing.md` | Documents the announcement and secret setup |

---

### Task 1: Thread building

**Files:**
- Create: `scripts/announce/thread.mjs`
- Test: `scripts/test/announce/thread.test.mjs`

**Interfaces:**
- Produces:
  - `extractHighlights(releaseBody: string): string[]`: plain-text highlight paragraphs, `[]` when there is no block
  - `graphemeLength(text: string): number`
  - `splitToFit(text: string, limit: number): string[]`
  - `buildThread({ tag: string, releaseUrl: string, highlights: string[], limit: number }): string[]`: the root post (homepage link, `#rss #rssreader`), the highlights, and a closing `Full release notes: <releaseUrl>` post
  - `HOMEPAGE_URL`, `HOMEPAGE_TITLE`, `HOMEPAGE_DESCRIPTION`: the homepage link and its card text, copied from the `og:` tags in `docs/homepage/index.html`

- [ ] **Step 1: Write the failing tests**

```js
// scripts/test/announce/thread.test.mjs
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `node --test scripts/test/announce/`
Expected: FAIL with `Cannot find module '…/scripts/announce/thread.mjs'`

- [ ] **Step 3: Implement**

```js
// scripts/announce/thread.mjs
const segmenter = new Intl.Segmenter('en', { granularity: 'grapheme' });

export function graphemeLength(text) {
  return [...segmenter.segment(text)].length;
}

export function extractHighlights(releaseBody) {
  const lines = releaseBody.replace(/\r\n/g, '\n').split('\n');
  const start = lines.findIndex((line) => /^###\s+Highlights\s*$/.test(line));
  if (start === -1) {
    return [];
  }
  const rest = lines.slice(start + 1);
  const end = rest.findIndex((line) => /^#{1,3}\s/.test(line));
  const block = (end === -1 ? rest : rest.slice(0, end)).flatMap((line) =>
    /^\s*[-*]\s+/.test(line) ? ['', line.replace(/^\s*[-*]\s+/, '')] : [line],
  );
  return block
    .join('\n')
    .split(/\n\s*\n/)
    .map((paragraph) => toPlainText(paragraph.split('\n').map((line) => line.trim()).join(' ')))
    .filter((paragraph) => paragraph !== '');
}

function toPlainText(markdown) {
  return markdown
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/\[([^\]]+)\]\((https?:[^)\s]+)\)/g, '$1 ($2)')
    .replace(/`([^`]+)`/g, '$1')
    .trim();
}

export function splitToFit(text, limit) {
  if (graphemeLength(text) <= limit) {
    return [text];
  }
  const pieces = text
    .split(/(?<=[.!?])\s+/)
    .flatMap((sentence) => (graphemeLength(sentence) <= limit ? [sentence] : wordsWithin(sentence, limit)));
  return pack(pieces, limit);
}

function wordsWithin(sentence, limit) {
  return sentence
    .split(/\s+/)
    .flatMap((word) => (graphemeLength(word) <= limit ? [word] : graphemeChunks(word, limit)));
}

function graphemeChunks(word, limit) {
  const graphemes = [...segmenter.segment(word)].map((segment) => segment.segment);
  const chunks = [];
  for (let index = 0; index < graphemes.length; index += limit) {
    chunks.push(graphemes.slice(index, index + limit).join(''));
  }
  return chunks;
}

function pack(pieces, limit) {
  const posts = [];
  let current = '';
  for (const piece of pieces) {
    const candidate = current === '' ? piece : `${current} ${piece}`;
    if (graphemeLength(candidate) <= limit) {
      current = candidate;
      continue;
    }
    posts.push(current);
    current = piece;
  }
  if (current !== '') {
    posts.push(current);
  }
  return posts;
}

export const HOMEPAGE_URL = 'https://lars-pohlmann.de/simplefeedreader/';
export const HOMEPAGE_TITLE = 'simple-feed-reader';
export const HOMEPAGE_DESCRIPTION =
  'A self-hosted RSS/Atom reader for one or several users. Magazine, list and two-pane layouts, podcasts, '
  + 'YouTube, Bluesky and Mastodon, optional AI recommendations. Free and open source.';

export function buildThread({ tag, releaseUrl, highlights, limit }) {
  return [
    `simple-feed-reader ${tag} is out. The highlights follow in this thread. #rss #rssreader\n\n${HOMEPAGE_URL}`,
    ...highlights.flatMap((highlight) => splitToFit(highlight, limit)),
    `Full release notes: ${releaseUrl}`,
  ];
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `node --test scripts/test/announce/`
Expected: PASS, 10 tests

- [ ] **Step 5: Commit**

```bash
git add scripts/announce/thread.mjs scripts/test/announce/thread.test.mjs
git commit -m "feat(#2096): build a release announcement thread from the highlights"
```

---

### Task 2: Mastodon poster

**Files:**
- Create: `scripts/announce/mastodon.mjs`
- Test: `scripts/test/announce/mastodon.test.mjs`

**Interfaces:**
- Produces:
  - `MASTODON_LIMIT = 500`
  - `postMastodonThread(posts: string[], { instance: string, token: string, fetch }): Promise<string>`: resolves to the root status URL and throws `Error` naming the post number and HTTP status on any non-2xx response.

- [ ] **Step 1: Write the failing tests**

```js
// scripts/test/announce/mastodon.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { postMastodonThread } from '../../announce/mastodon.mjs';

function fakeFetch(responses) {
  const calls = [];
  const fetch = async (url, init) => {
    calls.push({ url, init, body: JSON.parse(init.body) });
    const [status, payload] = responses.shift();
    return new Response(JSON.stringify(payload), { status });
  };
  return { fetch, calls };
}

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
```

- [ ] **Step 2: Run them to see them fail**

Run: `node --test scripts/test/announce/`
Expected: FAIL with `Cannot find module '…/scripts/announce/mastodon.mjs'`

- [ ] **Step 3: Implement**

```js
// scripts/announce/mastodon.mjs
import { createHash } from 'node:crypto';

export const MASTODON_LIMIT = 500;

export async function postMastodonThread(posts, { instance, token, fetch }) {
  const endpoint = `${instance.replace(/\/+$/, '')}/api/v1/statuses`;
  let parentId = null;
  let rootUrl = null;
  for (const [index, status] of posts.entries()) {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Idempotency-Key': createHash('sha256').update(`${parentId}\n${status}`).digest('hex'),
      },
      body: JSON.stringify({
        status,
        visibility: 'public',
        language: 'en',
        ...(parentId === null ? {} : { in_reply_to_id: parentId }),
      }),
    });
    if (!response.ok) {
      throw new Error(`Mastodon refused post ${index + 1}: HTTP ${response.status} ${await response.text()}`);
    }
    const posted = await response.json();
    parentId = posted.id;
    rootUrl ??= posted.url;
  }
  return rootUrl;
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `node --test scripts/test/announce/`
Expected: PASS, 13 tests

- [ ] **Step 5: Commit**

```bash
git add scripts/announce/mastodon.mjs scripts/test/announce/mastodon.test.mjs
git commit -m "feat(#2096): post a release thread to Mastodon"
```

---

### Task 3: Bluesky poster

**Files:**
- Create: `scripts/announce/bluesky.mjs`
- Test: `scripts/test/announce/bluesky.test.mjs`

**Interfaces:**
- Produces:
  - `BLUESKY_LIMIT = 300`
  - `DEFAULT_BLUESKY_SERVICE = 'https://bsky.social'`
  - `richTextFacets(text: string): object[]`: one `app.bsky.richtext.facet#link` per `http(s)` URL, with trailing `.,;:!?` left out of the link, and one `app.bsky.richtext.facet#tag` per `#hashtag` at the start of the text or after whitespace. Offsets are UTF-8 bytes, and facets are sorted by `byteStart`.
  - `postBlueskyThread(posts: string[], { service: string, handle: string, appPassword: string, card: { uri, title, description, thumb: { bytes: Uint8Array, mimeType: string } | null }, fetch }): Promise<string>`: the root post carries the card as an `app.bsky.embed.external`, with `thumb` uploaded through `com.atproto.repo.uploadBlob` when given. Resolves to the root post's `https://bsky.app/profile/<did>/post/<rkey>` URL. Throws an `Error` naming the step and HTTP status on any non-2xx response.

- [ ] **Step 1: Write the failing tests**

```js
// scripts/test/announce/bluesky.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `node --test scripts/test/announce/`
Expected: FAIL with `Cannot find module '…/scripts/announce/bluesky.mjs'`

- [ ] **Step 3: Implement**

```js
// scripts/announce/bluesky.mjs
export const BLUESKY_LIMIT = 300;
export const DEFAULT_BLUESKY_SERVICE = 'https://bsky.social';

export function richTextFacets(text) {
  const links = [...text.matchAll(/https?:\/\/\S+/g)].map((match) => {
    const uri = match[0].replace(/[.,;:!?]+$/, '');
    return facet(text, match.index, uri, { $type: 'app.bsky.richtext.facet#link', uri });
  });
  const tags = [...text.matchAll(/(?<=^|\s)#([\p{L}\p{N}_]+)/gu)].map((match) =>
    facet(text, match.index, match[0], { $type: 'app.bsky.richtext.facet#tag', tag: match[1] }),
  );
  return [...links, ...tags].sort((left, right) => left.index.byteStart - right.index.byteStart);
}

function facet(text, index, matched, feature) {
  const byteStart = Buffer.byteLength(text.slice(0, index));
  return { index: { byteStart, byteEnd: byteStart + Buffer.byteLength(matched) }, features: [feature] };
}

export async function postBlueskyThread(posts, { service, handle, appPassword, card, fetch }) {
  const xrpc = `${service.replace(/\/+$/, '')}/xrpc`;
  const session = await call(fetch, `${xrpc}/com.atproto.server.createSession`, {
    body: JSON.stringify({ identifier: handle, password: appPassword }),
  }, 'Bluesky login failed');
  const embed = await cardEmbed(card, async ({ bytes, mimeType }) => {
    const uploaded = await call(fetch, `${xrpc}/com.atproto.repo.uploadBlob`, {
      accessJwt: session.accessJwt, contentType: mimeType, body: bytes,
    }, 'Bluesky refused the card image');
    return uploaded.blob;
  });
  let root = null;
  let parent = null;
  for (const [index, text] of posts.entries()) {
    const record = {
      $type: 'app.bsky.feed.post',
      text,
      createdAt: new Date().toISOString(),
      langs: ['en'],
      facets: richTextFacets(text),
      ...(root === null ? { embed } : { reply: { root, parent } }),
    };
    const created = await call(fetch, `${xrpc}/com.atproto.repo.createRecord`, {
      accessJwt: session.accessJwt,
      body: JSON.stringify({ repo: session.did, collection: 'app.bsky.feed.post', record }),
    }, `Bluesky refused post ${index + 1}`);
    parent = { uri: created.uri, cid: created.cid };
    root ??= parent;
  }
  return `https://bsky.app/profile/${session.did}/post/${root.uri.split('/').pop()}`;
}

async function cardEmbed({ uri, title, description, thumb }, uploadThumb) {
  const external = { uri, title, description };
  return {
    $type: 'app.bsky.embed.external',
    external: thumb === null ? external : { ...external, thumb: await uploadThumb(thumb) },
  };
}

async function call(fetch, url, { accessJwt = null, contentType = 'application/json', body }, failure) {
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': contentType,
      ...(accessJwt === null ? {} : { Authorization: `Bearer ${accessJwt}` }),
    },
    body,
  });
  if (!response.ok) {
    throw new Error(`${failure}: HTTP ${response.status} ${await response.text()}`);
  }
  return response.json();
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `node --test scripts/test/announce/`
Expected: PASS, 21 tests

- [ ] **Step 5: Commit**

```bash
git add scripts/announce/bluesky.mjs scripts/test/announce/bluesky.test.mjs
git commit -m "feat(#2096): post a release thread to Bluesky"
```

---

### Task 4: Orchestration, CLI, and CI

**Files:**
- Create: `scripts/announce/announce.mjs`, `scripts/announce-release.mjs`
- Test: `scripts/test/announce/announce.test.mjs`
- Modify: `.github/workflows/ci.yml` (`scripts` job, after the ShellCheck step)

**Interfaces:**
- Consumes: `extractHighlights`, `buildThread`, `HOMEPAGE_URL`, `HOMEPAGE_TITLE` and `HOMEPAGE_DESCRIPTION` (Task 1), `MASTODON_LIMIT` and `postMastodonThread` (Task 2), `BLUESKY_LIMIT`, `DEFAULT_BLUESKY_SERVICE` and `postBlueskyThread` (Task 3)
- Produces:
  - `announce({ tag, releaseUrl, releaseBody, cardImage, env, fetch, log, dryRun }): Promise<number>`: the exit code, `0` when every configured network posted or there was nothing to post. `cardImage` is `{ bytes, mimeType } | null`, the Bluesky card thumbnail; the CLI reads `docs/homepage/share.png`.
  - CLI `node scripts/announce-release.mjs <tag> <release-url> [--dry-run] < body.md`

- [ ] **Step 1: Write the failing tests**

```js
// scripts/test/announce/announce.test.mjs
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

function respondingFetch() {
  const urls = [];
  const fetch = async (url) => {
    urls.push(url);
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
  assert.equal(urls.filter((url) => url.endsWith('/createRecord')).length, 3);
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
  const urls = [];
  const fetch = async (url) => {
    urls.push(url);
    if (url.startsWith('https://m.example')) {
      return new Response('{"error":"down"}', { status: 503 });
    }
    const payload = url.includes('createSession')
      ? { accessJwt: 'jwt', did: 'did:plc:x' }
      : { uri: 'at://did:plc:x/app.bsky.feed.post/r', cid: 'c' };
    return new Response(JSON.stringify(payload), { status: 200 });
  };

  const code = await announce({ ...base, env: { ...mastodonEnv, ...blueskyEnv }, fetch, log });

  assert.equal(code, 1);
  assert.ok(lines.some((line) => line.startsWith('::error::Mastodon: Mastodon refused post 1: HTTP 503')));
  assert.equal(urls.filter((url) => url.endsWith('/createRecord')).length, 3);
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
```

- [ ] **Step 2: Run them to see them fail**

Run: `node --test scripts/test/announce/`
Expected: FAIL with `Cannot find module '…/scripts/announce/announce.mjs'`

- [ ] **Step 3: Implement the orchestration**

```js
// scripts/announce/announce.mjs
import { BLUESKY_LIMIT, DEFAULT_BLUESKY_SERVICE, postBlueskyThread } from './bluesky.mjs';
import { MASTODON_LIMIT, postMastodonThread } from './mastodon.mjs';
import { buildThread, extractHighlights, HOMEPAGE_DESCRIPTION, HOMEPAGE_TITLE, HOMEPAGE_URL } from './thread.mjs';

export async function announce({ tag, releaseUrl, releaseBody, cardImage, env, fetch, log, dryRun }) {
  const highlights = extractHighlights(releaseBody);
  if (highlights.length === 0) {
    log(`${tag} has no highlights; nothing to announce.`);
    return 0;
  }
  let failures = 0;
  for (const network of networks({ cardImage, env, fetch })) {
    const posts = buildThread({ tag, releaseUrl, highlights, limit: network.limit });
    if (dryRun) {
      log(`--- ${network.name} (${posts.length} posts)`);
      posts.forEach((post, index) => log(`[${index + 1}] ${post}`));
      continue;
    }
    if (!network.configured) {
      log(`::warning::${network.name} secrets are not set; skipping.`);
      continue;
    }
    try {
      log(`${network.name}: ${await network.post(posts)}`);
    } catch (error) {
      failures += 1;
      log(`::error::${network.name}: ${error.message}`);
    }
  }
  return failures === 0 ? 0 : 1;
}

function networks({ cardImage, env, fetch }) {
  return [
    {
      name: 'Mastodon',
      limit: MASTODON_LIMIT,
      configured: Boolean(env.MASTODON_INSTANCE && env.MASTODON_TOKEN),
      post: (posts) => postMastodonThread(posts, { instance: env.MASTODON_INSTANCE, token: env.MASTODON_TOKEN, fetch }),
    },
    {
      name: 'Bluesky',
      limit: BLUESKY_LIMIT,
      configured: Boolean(env.BLUESKY_HANDLE && env.BLUESKY_APP_PASSWORD),
      post: (posts) => postBlueskyThread(posts, {
        service: env.BLUESKY_SERVICE || DEFAULT_BLUESKY_SERVICE,
        handle: env.BLUESKY_HANDLE,
        appPassword: env.BLUESKY_APP_PASSWORD,
        card: { uri: HOMEPAGE_URL, title: HOMEPAGE_TITLE, description: HOMEPAGE_DESCRIPTION, thumb: cardImage },
        fetch,
      }),
    },
  ];
}
```

- [ ] **Step 4: Implement the CLI**

```js
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
```

Then make it executable: `chmod +x scripts/announce-release.mjs`

- [ ] **Step 5: Run the tests to see them pass, then dry-run against v1.9.0**

Run: `node --test scripts/test/announce/`
Expected: PASS, 26 tests

Run:
```bash
gh release view v1.9.0 --json body --jq .body | node scripts/announce-release.mjs v1.9.0 https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.9.0 --dry-run
```
Expected: a Mastodon thread and a Bluesky thread, each with 3 posts:
- `[1]` is the root post, with `#rss #rssreader` and the homepage link.
- `[2]` is `Social searches as feeds. You can now subscribe to a Bluesky or Mastodon search, and it shows up as a feed of its own under a new Searches group in the sidebar.`
- `[3]` is `Full release notes: https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.9.0`.

- [ ] **Step 6: Run the tests in CI**

In `.github/workflows/ci.yml`, in the `scripts` job, directly after the `ShellCheck` step, add:

```yaml
      - uses: actions/setup-node@v4
        with:
          node-version: '22'

      - name: announce-release
        run: node --test scripts/test/announce/
```

- [ ] **Step 7: Commit**

```bash
git add scripts/announce/announce.mjs scripts/announce-release.mjs scripts/test/announce/announce.test.mjs .github/workflows/ci.yml
git commit -m "feat(#2096): announce a release on every configured network"
```

---

### Task 5: Workflows and docs

**Files:**
- Create: `.github/workflows/announce-release.yml`
- Modify: `.github/workflows/release.yml` (header comment, plus a new `announce` job after `manifests`)
- Modify: `docs/releasing.md` (bullet list at the top, plus a new `## Announcements` section before `## Steps`)

**Interfaces:**
- Consumes: the CLI from Task 4
- Produces: the workflow `announce-release.yml`, with `workflow_call` input `tag` and `workflow_dispatch` input `tag`

- [ ] **Step 1: Create the reusable workflow**

```yaml
# .github/workflows/announce-release.yml
# Posts a release's highlights as a thread to Bluesky and Mastodon (#2096).
# release.yml calls it once the images are tagged; run it by hand to announce
# an existing release. A re-run posts again: Mastodon dedupes within an hour,
# Bluesky does not.
name: Announce release

on:
  workflow_call:
    inputs:
      tag:
        required: true
        type: string
  workflow_dispatch:
    inputs:
      tag:
        description: Release tag to announce, e.g. v1.9.0
        required: true
        type: string

permissions:
  contents: read

jobs:
  announce:
    name: Announce ${{ inputs.tag }}
    runs-on: ubuntu-latest
    timeout-minutes: 5
    if: github.repository == 'larspohlmann/simple-feed-reader'

    steps:
      - uses: actions/checkout@v5

      - uses: actions/setup-node@v4
        with:
          node-version: '22'

      - name: Verify the announcer
        run: node --test scripts/test/announce/

      - name: Announce
        env:
          GH_TOKEN: ${{ github.token }}
          TAG: ${{ inputs.tag }}
          MASTODON_INSTANCE: ${{ secrets.MASTODON_INSTANCE }}
          MASTODON_TOKEN: ${{ secrets.MASTODON_TOKEN }}
          BLUESKY_HANDLE: ${{ secrets.BLUESKY_HANDLE }}
          BLUESKY_APP_PASSWORD: ${{ secrets.BLUESKY_APP_PASSWORD }}
          BLUESKY_SERVICE: ${{ secrets.BLUESKY_SERVICE }}
        run: |
          set -euo pipefail
          if ! [[ "${TAG}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            echo "!!! ${TAG} is not a vX.Y.Z release tag." >&2
            exit 1
          fi
          url="$(gh release view "${TAG}" --repo "${GITHUB_REPOSITORY}" --json url --jq .url)"
          gh release view "${TAG}" --repo "${GITHUB_REPOSITORY}" --json body --jq .body \
            | node scripts/announce-release.mjs "${TAG}" "${url}"
```

- [ ] **Step 2: Call it from release.yml**

Append to the jobs in `.github/workflows/release.yml`, after `manifests`:

```yaml
  # Posts the highlights once the images exist (#2096); a release without
  # highlights posts nothing.
  announce:
    name: Announce
    needs: manifests
    uses: ./.github/workflows/announce-release.yml
    with:
      tag: ${{ github.ref_name }}
    secrets: inherit
    permissions:
      contents: read
```

Also extend the header comment's first sentence: "…pushes the release's php and web images to GHCR, and announces its highlights on Bluesky and Mastodon."

- [ ] **Step 3: Validate the workflow files**

Run: `actionlint .github/workflows/announce-release.yml .github/workflows/release.yml .github/workflows/ci.yml` (if actionlint is not installed: `brew install actionlint`)
Expected: no output

- [ ] **Step 4: Document it in docs/releasing.md**

Add to the bullet list at the top, after the images bullet:

```markdown
- once the images are tagged, posts the release's highlights as a thread to
  Bluesky and Mastodon (see [Announcements](#announcements)),
```

Add before `## Steps`:

````markdown
## Announcements

When the `### Highlights` block under `## [Unreleased]` is non-empty at tag time,
[`announce-release.yml`](../.github/workflows/announce-release.yml) posts a thread
to each configured network:
- a root post that links the [homepage](https://lars-pohlmann.de/simplefeedreader/)
  and carries `#rss #rssreader`. On Bluesky it shows a card with the homepage's
  share image.
- each highlight as a reply, split at sentence ends when it is longer than
  Bluesky's 300 or Mastodon's 500 characters.
- a closing reply that links the GitHub Release.

A release without highlights posts nothing.

Repository secrets (a network whose secrets are missing is skipped with a warning):

| Secret | Value |
|---|---|
| `MASTODON_INSTANCE` | e.g. `https://mastodon.social` |
| `MASTODON_TOKEN` | Preferences → Development → New application, scope `write:statuses` only |
| `BLUESKY_HANDLE` | e.g. `lars.bsky.social` |
| `BLUESKY_APP_PASSWORD` | Settings → Privacy and security → App passwords |
| `BLUESKY_SERVICE` | optional; your PDS if not `https://bsky.social` |

```bash
gh secret set MASTODON_TOKEN
```

Preview a thread locally without posting:

```bash
gh release view v1.9.0 --json body --jq .body \
  | node scripts/announce-release.mjs v1.9.0 https://github.com/larspohlmann/simple-feed-reader/releases/tag/v1.9.0 --dry-run
```

Announce an existing release by hand: Actions → Announce release → Run workflow,
with the tag. A re-run posts again; delete the duplicate by hand if one network
had already succeeded.
````

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/announce-release.yml .github/workflows/release.yml docs/releasing.md
git commit -m "feat(#2096): announce releases with highlights after the images are tagged"
```

---

### After the tasks

- Whole-branch review, then `/simplify`, then the gates: `node --test scripts/test/announce/`, `actionlint`, `shellcheck scripts/*.sh scripts/test/*.sh` (unchanged, but CI runs it).
- Open the PR into `develop` with `Closes #2096`.
- After Lars sets the secrets: run the workflow by hand for `v1.9.0`, which is the first real end-to-end check.
