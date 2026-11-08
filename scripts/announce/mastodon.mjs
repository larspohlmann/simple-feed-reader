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
