/**
 * Removes an article-body player for the file the entry's Listen control
 * already plays (#1434): WordPress/PowerPress pages embed their own `<audio>`
 * for the episode, often with a `?_=N` cache-buster on the source, and
 * broadcasters serve it from another CDN host and path (#2577).
 */
export function removeEnclosurePlayers(host: HTMLElement, enclosureUrl: string | null): void {
  const enclosure = enclosureUrl && fileOf(enclosureUrl);
  if (!enclosure) return;
  for (const player of Array.from(host.querySelectorAll('audio'))) {
    if (sourcesOf(player).some((source) => fileOf(source) === enclosure)) player.remove();
  }
}

function sourcesOf(player: HTMLAudioElement): string[] {
  const sources = Array.from(player.querySelectorAll('source'), (source) =>
    source.getAttribute('src'),
  );
  return [player.getAttribute('src'), ...sources].filter((source) => source !== null);
}

function fileOf(url: string): string | null {
  try {
    return new URL(url).pathname.split('/').pop() || null;
  } catch {
    return null;
  }
}
