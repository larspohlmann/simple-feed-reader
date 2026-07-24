// src/app/core/gravatar.ts

/** Gravatar hashes the trimmed, lower-cased email. */
export function normalizeEmail(email: string): string {
  return email.trim().toLowerCase();
}

/** Hex SHA-256 of a string via Web Crypto — Gravatar accepts SHA-256 hashes,
 *  and it avoids shipping an MD5 implementation (and keeps this native-ready). */
export async function sha256Hex(input: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
  return Array.from(new Uint8Array(digest))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

/**
 * Gravatar avatar URL for an email hash. `d=404` makes Gravatar return 404 for
 * an address with no avatar, so the <img> error handler can fall back to the
 * placeholder icon rather than showing Gravatar's default silhouette.
 */
export function gravatarUrl(hashHex: string, size: number): string {
  return `https://www.gravatar.com/avatar/${hashHex}?s=${size}&d=404`;
}
