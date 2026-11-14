/** Adds `incoming` to `existing`, deduped case-insensitively, keeping first-seen
 *  casing — mirrors `MeilisearchIndex::matchedWordsOf`. A case-only duplicate
 *  would just lengthen the marker pattern for no benefit (search-marks.ts). */
export function unionMatchedWords(existing: string[], incoming: string[]): string[] {
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
