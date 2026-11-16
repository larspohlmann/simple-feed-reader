const MENTION_PATH = /^\/(?:@[^/]+|users\/[^/]+)$/;
const HASHTAG_PATH = /^\/tags\/[^/]+$/;

function parseHttpUrl(value: string): URL | null {
  if (!URL.canParse(value)) {
    return null;
  }
  const parsed = new URL(value);
  return /^https?:$/.test(parsed.protocol) ? parsed : null;
}

function authorizeInteraction(instance: string, address: string): string {
  return `${instance}/authorize_interaction?uri=${encodeURIComponent(address)}`;
}

export function instanceOrigin(serviceUrl: string): string | null {
  return parseHttpUrl(serviceUrl)?.origin ?? null;
}

export function ownInstancePostUrl(address: string, instance: string | null): string {
  const target = parseHttpUrl(address);
  if (instance === null || target === null || target.origin === instance) {
    return address;
  }
  return authorizeInteraction(instance, address);
}

export function ownInstanceBodyUrl(href: string, text: string, instance: string | null): string {
  const target = parseHttpUrl(href);
  if (instance === null || target === null || target.origin === instance) {
    return href;
  }
  if (text.startsWith('#') && HASHTAG_PATH.test(target.pathname)) {
    return `${instance}${target.pathname}`;
  }
  if (text.startsWith('@') && MENTION_PATH.test(target.pathname)) {
    return authorizeInteraction(instance, href);
  }
  return href;
}
