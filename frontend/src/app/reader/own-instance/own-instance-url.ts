import { parseUrl } from '../../shared/url-host';

const MENTION_PATH = /^\/(?:@[^/]+|users\/[^/]+)$/;
const HASHTAG_PATH = /^\/tags\/[^/]+$/;

function parseHttpUrl(value: string): URL | null {
  const parsed = parseUrl(value);
  return parsed !== null && /^https?:$/.test(parsed.protocol) ? parsed : null;
}

function foreignTarget(address: string, instance: string | null): URL | null {
  const target = parseHttpUrl(address);
  return instance === null || target === null || target.origin === instance ? null : target;
}

function authorizeInteraction(instance: string, address: string): string {
  return `${instance}/authorize_interaction?uri=${encodeURIComponent(address)}`;
}

export function instanceOrigin(serviceUrl: string): string | null {
  return parseHttpUrl(serviceUrl)?.origin ?? null;
}

export function ownInstancePostUrl(address: string, instance: string | null): string {
  return instance !== null && foreignTarget(address, instance) !== null
    ? authorizeInteraction(instance, address)
    : address;
}

export function ownInstanceBodyUrl(href: string, text: string, instance: string | null): string {
  const target = foreignTarget(href, instance);
  if (instance === null || target === null) {
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
