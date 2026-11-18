import { accountSignal } from '../../core/auth/session-identity';
import { EntriesPage } from '../models';

/** Words the current search engine actually matched (empty outside a search, or when the LIKE fallback answered
 *  it). A new result set replaces them; a further page unions into them, since the earlier pages' rows are still
 *  on screen and still marked by theirs. Built in a field initializer: its words reset with the account. */
export class MatchedWords {
  private readonly matched = accountSignal<string[]>([]);
  readonly words = this.matched.asReadonly();

  replaceWith(page: Pick<EntriesPage, 'matchedWords'>): void {
    this.matched.set(page.matchedWords ?? []);
  }

  addFrom(page: Pick<EntriesPage, 'matchedWords'>): void {
    this.matched.update((existing) => unionMatchedWords(existing, page.matchedWords ?? []));
  }

  clear(): void {
    this.matched.set([]);
  }
}

/** Adds `incoming` to `existing`, deduped case-insensitively, keeping first-seen
 *  casing — mirrors `MeilisearchIndex::matchedWordsOf`. A case-only duplicate
 *  would just lengthen the marker pattern for no benefit (search-marks.ts). */
function unionMatchedWords(existing: string[], incoming: string[]): string[] {
  const seen = new Set(existing.map((word) => word.toLowerCase()));
  const union = [...existing];
  for (const word of incoming) {
    const key = word.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    union.push(word);
  }
  return union;
}
