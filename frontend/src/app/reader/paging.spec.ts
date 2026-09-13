import { MIN_PREFETCH_MARGIN, PAGE_SIZE, isAppendedPage, prefetchMargin } from './paging';

describe('paging', () => {
  it('asks for a full page, within the backend cap', () => {
    expect(PAGE_SIZE).toBe(100);
  });

  it('leads the scroll position by more than one viewport', () => {
    // 900px of visible list must start the next fetch well before the user
    // reaches the end of it — otherwise a slow backend shows a spinner.
    expect(prefetchMargin(900)).toBe('1350px');
  });

  it('scales with the scroll container, so a tall window leads further', () => {
    expect(prefetchMargin(1600)).toBe('2400px');
  });

  it('falls back to the floor when the container has not been measured yet', () => {
    // The effect can run before layout, where clientHeight is still 0; a 0px
    // margin would defeat prefetching entirely.
    expect(prefetchMargin(0)).toBe(`${MIN_PREFETCH_MARGIN}px`);
    expect(prefetchMargin(100)).toBe(`${MIN_PREFETCH_MARGIN}px`);
  });

  it('rounds to whole pixels', () => {
    expect(prefetchMargin(801)).toBe('1202px');
  });
});

describe('isAppendedPage', () => {
  const entries = (ids: number[]): { id: number }[] => ids.map((id) => ({ id }));

  it('recognises a next page appended behind the loaded list', () => {
    const previous = entries([1, 2, 3]);
    const next = [...previous, ...entries([4, 5])];
    expect(isAppendedPage(previous, next)).toBe(true);
  });

  it('treats a replaced list as a fresh render, not an append', () => {
    expect(isAppendedPage(entries([1, 2, 3]), entries([9, 10, 11, 12]))).toBe(false);
  });

  it('treats the first page, a same-length update and a shrink as fresh renders', () => {
    const loaded = entries([1, 2, 3]);
    expect(isAppendedPage([], loaded)).toBe(false);
    expect(isAppendedPage(loaded, [loaded[0], { id: 2 }, loaded[2]])).toBe(false);
    expect(isAppendedPage(loaded, loaded.slice(0, 2))).toBe(false);
  });
});
