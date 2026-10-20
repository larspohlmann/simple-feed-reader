/** The `?link=` value: a URL and the server's signature of it. */
export interface SignedUrl {
  readonly url: string;
  readonly token: string;
}

const PARAM = /^([A-Za-z0-9_-]+)~(https?:\/\/.+)$/i;

export function linkParam(target: SignedUrl): string {
  return `${target.token}~${target.url}`;
}

export function signedUrlFromParam(value: string | null): SignedUrl | null {
  const match = PARAM.exec(value ?? '');
  return match === null ? null : { token: match[1], url: match[2] };
}

export function sameSignedUrl(left: SignedUrl | null, right: SignedUrl | null): boolean {
  return left?.url === right?.url && left?.token === right?.token;
}

/** Opening or closing an entry drops the link layer above it. */
export function entryChangeParams(entry: string | null): { entry: string | null; link: null } {
  return { entry, link: null };
}
