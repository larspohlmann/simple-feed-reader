export interface ItemRef {
  id: number;
  canonicalItemId: number | null;
}

/** Names the item an entry is: every copy of a canonical item shares one key, any other entry has its own. */
export function buildItemKey(entry: ItemRef): string {
  return entry.canonicalItemId === null ? `entry:${entry.id}` : `item:${entry.canonicalItemId}`;
}
