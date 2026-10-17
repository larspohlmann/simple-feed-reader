import { EntryDto } from '../models';
import { PlacedLinkCardDto } from './link-card';

export function linkCardAt(entry: EntryDto, position: number): PlacedLinkCardDto | null {
  return (
    entry.linkCards.find((card) => card.position === position) ??
    entry.thread.map((reply) => reply.linkCard).find((card) => card?.position === position) ??
    null
  );
}
