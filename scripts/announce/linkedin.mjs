const API = 'https://api.linkedin.com/rest';
const API_VERSION = '202610';

export function escapeLittleText(text) {
  return text.replace(/[|{}@[\]()<>\\*_~]/g, '\\$&');
}

export async function postLinkedIn(commentary, { token, author, card, fetch }) {
  const article = { source: card.uri, title: card.title, description: card.description };
  if (card.thumb !== null) {
    article.thumbnail = await uploadImage(fetch, token, author, card.thumb);
  }
  const response = await call(fetch, `${API}/posts`, token, {
    author,
    commentary: escapeLittleText(commentary),
    visibility: 'PUBLIC',
    distribution: { feedDistribution: 'MAIN_FEED', targetEntities: [], thirdPartyDistributionChannels: [] },
    content: { article },
    lifecycleState: 'PUBLISHED',
    isReshareDisabledByAuthor: false,
  }, 'LinkedIn refused the post');
  return `https://www.linkedin.com/feed/update/${response.headers.get('x-restli-id')}/`;
}

async function uploadImage(fetch, token, owner, { bytes, mimeType }) {
  const failure = 'LinkedIn refused the card image';
  const response = await call(fetch, `${API}/images?action=initializeUpload`, token, { initializeUploadRequest: { owner } }, failure);
  const { uploadUrl, image } = (await response.json()).value;
  const upload = await fetch(uploadUrl, {
    method: 'PUT',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': mimeType },
    body: bytes,
  });
  await assertOk(upload, failure);
  return image;
}

async function call(fetch, url, token, payload, failure) {
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      'Linkedin-Version': API_VERSION,
      'X-Restli-Protocol-Version': '2.0.0',
    },
    body: JSON.stringify(payload),
  });
  await assertOk(response, failure);
  return response;
}

async function assertOk(response, failure) {
  if (!response.ok) {
    throw new Error(`${failure}: HTTP ${response.status} ${await response.text()}`);
  }
}
