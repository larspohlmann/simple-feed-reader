import { LinkCardDto } from '../link-card/link-card';

/** The `?link=` value: a card's URL and the server's signature of it. */
export interface LinkCardRef {
  readonly url: string;
  readonly token: string;
}

const PARAM = /^([A-Za-z0-9_-]+)~(https?:\/\/.+)$/i;

export function refOf(card: LinkCardDto): LinkCardRef {
  return { url: card.url, token: card.readerToken };
}

export function linkParam(ref: LinkCardRef): string {
  return `${ref.token}~${ref.url}`;
}

export function linkCardFromParam(value: string | null): LinkCardRef | null {
  const match = PARAM.exec(value ?? '');
  return match === null ? null : { token: match[1], url: match[2] };
}

export function sameLinkCard(left: LinkCardRef | null, right: LinkCardRef | null): boolean {
  return left?.url === right?.url && left?.token === right?.token;
}

/** Opening or closing an entry drops the link layer above it. */
export function entryChangeParams(entry: string | null): { entry: string | null; link: null } {
  return { entry, link: null };
}
