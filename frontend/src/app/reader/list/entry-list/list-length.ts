export interface LoadedRows {
  /** The first row's top, in scroll coordinates. */
  readonly rowsTop: number;
  /** The last row's bottom, in scroll coordinates. */
  readonly rowsBottom: number;
  /** Entries visible between those edges: the average row height divides by these. */
  readonly shownEntries: number;
  /** Entries rendered, hidden ones included: the remainder of the total starts after these. */
  readonly renderedEntries: number;
  readonly totalEntries: number | null;
  /** Every entry is rendered, so the rows' bottom is the list's end. */
  readonly complete: boolean;
}

/** Where a paged list would end with every entry loaded, in scroll coordinates. */
export function estimatedListBottom(rows: LoadedRows): number | null {
  if (rows.complete) return rows.rowsBottom;
  if (rows.totalEntries === null || rows.shownEntries === 0) return null;
  const unrendered = Math.max(0, rows.totalEntries - rows.renderedEntries);
  const perEntry = (rows.rowsBottom - rows.rowsTop) / rows.shownEntries;
  return rows.rowsBottom + unrendered * perEntry;
}
