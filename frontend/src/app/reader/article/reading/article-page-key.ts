/** The key an article page remembers its scroll position under. */
export function entryPageKey(entryId: number): string {
  return `entry:${entryId}`;
}

export function linkPageKey(url: string): string {
  return `link:${url}`;
}
