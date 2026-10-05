import { estimatedListBottom, LoadedRows } from './list-length';

const rows = (overrides: Partial<LoadedRows>): LoadedRows => ({
  rowsTop: 100,
  rowsBottom: 1100,
  shownEntries: 10,
  renderedEntries: 10,
  totalEntries: 30,
  complete: false,
  ...overrides,
});

describe('estimatedListBottom', () => {
  it('extends the rendered rows by the unrendered entries at the average height', () => {
    expect(estimatedListBottom(rows({}))).toBe(1100 + 20 * 100);
  });

  it('is the real bottom once every entry is rendered, whatever the total says', () => {
    expect(estimatedListBottom(rows({ complete: true, totalEntries: 500 }))).toBe(1100);
    expect(estimatedListBottom(rows({ complete: true, totalEntries: null }))).toBe(1100);
  });

  it('is unknown while more pages remain and no total exists', () => {
    expect(estimatedListBottom(rows({ totalEntries: null }))).toBeNull();
  });

  it('averages over the shown entries but counts the remainder from the rendered ones', () => {
    // 5 hidden above (mark-above-read): 10 rendered, 5 shown over the same 1000px.
    expect(estimatedListBottom(rows({ shownEntries: 5 }))).toBe(1100 + 20 * 200);
  });

  it('never estimates fewer rows than are rendered', () => {
    expect(estimatedListBottom(rows({ totalEntries: 4 }))).toBe(1100);
  });

  it('is unknown with nothing shown to average over', () => {
    expect(estimatedListBottom(rows({ shownEntries: 0 }))).toBeNull();
  });
});
