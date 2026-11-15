export function parseUrl(url: string): URL | null {
  try {
    return new URL(url.trim());
  } catch {
    return null;
  }
}

/** The host a reader names a site by: without its `www.`. */
export function hostOf(url: URL): string {
  return url.hostname.replace(/^www\./, '');
}
