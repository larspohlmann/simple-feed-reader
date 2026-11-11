/** The backend's FeedDirectoryUrl rule; feed-directory-url-cases.json keeps the two in step. */
export function feedDirectoryUrl(url: string): string {
  const parsed = parseUrl(url);
  if (!parsed) return url.toLowerCase();
  const path = parsed.search ? parsed.pathname : parsed.pathname.replace(/\/+$/, '');
  const port = parsed.port ? `:${parsed.port}` : '';
  return `${hostOf(parsed)}${port}${path}${parsed.search}`;
}

export function feedDirectoryHost(url: string): string {
  const parsed = parseUrl(url);
  return parsed ? hostOf(parsed) : '';
}

export function parseUrl(url: string): URL | null {
  try {
    return new URL(url.trim());
  } catch {
    return null;
  }
}

export function hostOf(url: URL): string {
  return url.hostname.replace(/^www\./, '');
}
