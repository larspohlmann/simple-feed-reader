import { SocialNetworkName } from '../../core/social-network';
import { parseUrl } from '../../shared/url-host';

export interface OwnInstances {
  mastodon: string | null;
  lemmy: string | null;
  lemmyHosts: ReadonlySet<string>;
}

export interface LinkFacts {
  network?: SocialNetworkName | null;
  text?: string;
  homeUrl?: string | null;
}

const MASTODON_STATUS_PATHS = [/^\/@[^/]+\/\d+$/, /^\/users\/[^/]+\/statuses\/\d+$/];
const MENTION_PATH = /^\/(?:@[^/]+|users\/[^/]+)$/;
const HASHTAG_PATH = /^\/tags\/[^/]+$/;
const LEMMY_THREAD_PATH = /^\/(?:post|comment)\/\d+$/;
const LEMMY_ACTOR_PATH = /^\/([cu])\/([^/@]+)(?:@([^/]+))?$/;

function parseHttpUrl(value: string): URL | null {
  const parsed = parseUrl(value);
  return parsed !== null && /^https?:$/.test(parsed.protocol) ? parsed : null;
}

export function instanceOrigin(serviceUrl: string): string | null {
  return parseHttpUrl(serviceUrl)?.origin ?? null;
}

interface Link {
  address: string;
  target: URL;
  facts: LinkFacts;
}

export function ownInstanceUrl(address: string, facts: LinkFacts, instances: OwnInstances): string {
  const target = parseHttpUrl(address);
  if (target === null || facts.network === 'bluesky') return address;
  const homeUrl = pickHomeUrl(facts.homeUrl, instances.lemmy);
  if (homeUrl !== null) return homeUrl;
  const link: Link = { address, target, facts };
  const moved = isLemmyLink(link, instances)
    ? lemmyTarget(link, instances.lemmy)
    : mastodonTarget(link, instances.mastodon);
  return moved ?? address;
}

function pickHomeUrl(homeUrl: string | null | undefined, instance: string | null): string | null {
  if (homeUrl == null || instance === null) return null;
  return parseHttpUrl(homeUrl)?.origin === instance ? homeUrl : null;
}

function isLemmyLink({ target, facts }: Link, instances: OwnInstances): boolean {
  return (
    facts.network === 'lemmy' ||
    (facts.network == null && instances.lemmyHosts.has(target.hostname))
  );
}

function lemmyTarget({ address, target }: Link, instance: string | null): string | null {
  if (instance === null || target.origin === instance) return null;
  if (LEMMY_THREAD_PATH.test(target.pathname)) {
    return `${instance}/search?q=${encodeURIComponent(address)}&type=All`;
  }
  const actor = LEMMY_ACTOR_PATH.exec(target.pathname);
  if (actor === null) return null;
  const [, kind, name, domain] = actor;
  return `${instance}/${kind}/${name}@${domain ?? target.host}`;
}

function mastodonTarget({ address, target, facts }: Link, instance: string | null): string | null {
  if (instance === null || target.origin === instance) return null;
  const path = target.pathname;
  const text = facts.text ?? '';
  if (facts.network === 'mastodon' || MASTODON_STATUS_PATHS.some((shape) => shape.test(path))) {
    return authorizeInteraction(instance, address);
  }
  if (text.startsWith('#') && HASHTAG_PATH.test(path)) return `${instance}${path}`;
  if (text.startsWith('@') && MENTION_PATH.test(path)) {
    return authorizeInteraction(instance, address);
  }
  return null;
}

function authorizeInteraction(instance: string, address: string): string {
  return `${instance}/authorize_interaction?uri=${encodeURIComponent(address)}`;
}
