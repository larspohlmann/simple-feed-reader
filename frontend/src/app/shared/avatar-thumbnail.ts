const BLUESKY_FULL_AVATAR = /^(https:\/\/cdn\.bsky\.app\/img\/)avatar\//;

/** The full Bluesky avatar is 1000 px, about 4 MB decoded, for a 12–24 px icon (#1906). */
export function avatarThumbnailUrl(url: string | null): string | null {
  return url?.replace(BLUESKY_FULL_AVATAR, '$1avatar_thumbnail/') ?? null;
}
