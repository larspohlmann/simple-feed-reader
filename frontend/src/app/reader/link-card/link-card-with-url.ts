import { EntryDto } from '../models';
import { LinkCardDto } from './link-card';

export function linkCardWithUrl(entries: readonly EntryDto[], url: string): LinkCardDto | null {
  for (const entry of entries) {
    const card =
      entry.linkCards.find((candidate) => candidate.url === url) ??
      entry.thread.map((reply) => reply.linkCard).find((candidate) => candidate?.url === url);
    if (card) return card;
  }
  return null;
}
