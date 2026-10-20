import { EntryDto } from '../models';
import { LinkCardDto } from './link-card';

export function linkCardWithUrl(entries: readonly EntryDto[], url: string): LinkCardDto | null {
  for (const entry of entries) {
    const cards = [...entry.linkCards, ...entry.thread.flatMap((reply) => reply.linkCard ?? [])];
    const card = cards.find((candidate) => candidate.url === url);
    if (card) return card;
  }
  return null;
}
