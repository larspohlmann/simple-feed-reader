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
