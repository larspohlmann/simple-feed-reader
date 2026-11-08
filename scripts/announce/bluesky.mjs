export const BLUESKY_LIMIT = 300;
export const DEFAULT_BLUESKY_SERVICE = 'https://bsky.social';

export function richTextFacets(text) {
  const links = [...text.matchAll(/https?:\/\/\S+/g)].map((match) => {
    const uri = trimLink(match[0]);
    return facet(text, match.index, uri, { $type: 'app.bsky.richtext.facet#link', uri });
  });
  const tags = [...text.matchAll(/(?<=^|\s)#([\p{L}\p{N}_]+)/gu)].map((match) =>
    facet(text, match.index, match[0], { $type: 'app.bsky.richtext.facet#tag', tag: match[1] }),
  );
  return [...links, ...tags].sort((left, right) => left.index.byteStart - right.index.byteStart);
}

function trimLink(link) {
  let trimmed = link;
  for (;;) {
    const next = trimmed.replace(/[.,;:!?"']+$/, '');
    const unbalanced = next.endsWith(')') && count(next, ')') > count(next, '(');
    if (next === trimmed && !unbalanced) {
      return trimmed;
    }
    trimmed = unbalanced ? next.slice(0, -1) : next;
  }
}

function count(text, character) {
  return text.split(character).length - 1;
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
