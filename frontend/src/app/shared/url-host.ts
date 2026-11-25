export function parseUrl(url: string): URL | null {
  try {
    return new URL(url.trim());
  } catch {
    return null;
  }
}

export function parseHttpUrl(value: string): URL | null {
  const parsed = parseUrl(value);
  return parsed !== null && /^https?:$/.test(parsed.protocol) ? parsed : null;
}

/** The host a reader names a site by: without its `www.`. */
export function hostOf(url: URL): string {
  return stripWww(url.hostname);
}

export function stripWww(host: string): string {
  return host.startsWith('www.') ? host.slice('www.'.length) : host;
}
