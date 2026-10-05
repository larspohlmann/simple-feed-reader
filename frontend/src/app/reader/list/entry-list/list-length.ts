export interface LoadedRows {
  /** The first row's top, in scroll coordinates. */
  readonly rowsTop: number;
  /** The last row's bottom, in scroll coordinates. */
  readonly rowsBottom: number;
  /** Entries rendered between those edges (hidden rows excluded). */
  readonly shownEntries: number;
  /** Entries fetched so far. */
  readonly loadedEntries: number;
  /** Entries in the whole list, or null when nothing says. */
  readonly totalEntries: number | null;
  readonly hasMore: boolean;
}

/** Where a paged list would end with every entry loaded, in scroll coordinates. */
export function estimatedListBottom(rows: LoadedRows): number | null {
  if (!rows.hasMore) return rows.rowsBottom;
  if (rows.totalEntries === null || rows.shownEntries === 0) return null;
  const unloaded = Math.max(0, rows.totalEntries - rows.loadedEntries);
  const perEntry = (rows.rowsBottom - rows.rowsTop) / rows.shownEntries;
  return rows.rowsBottom + unloaded * perEntry;
}
