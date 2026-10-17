/** The `?link=` value: the card's entry id and its position among that entry's cards. */
export interface LinkCardRef {
  readonly entryId: number;
  readonly position: number;
}

export function linkParam(ref: LinkCardRef): string {
  return `${ref.entryId}.${ref.position}`;
}

export function linkCardFromParam(value: string | null): LinkCardRef | null {
  const match = /^(\d+)\.(\d+)$/.exec(value ?? '');
  if (match === null) return null;
  const entryId = Number(match[1]);
  const position = Number(match[2]);
  return entryId > 0 && Number.isSafeInteger(entryId) && Number.isSafeInteger(position)
    ? { entryId, position }
    : null;
}

export function sameLinkCard(left: LinkCardRef | null, right: LinkCardRef | null): boolean {
  return left?.entryId === right?.entryId && left?.position === right?.position;
}
