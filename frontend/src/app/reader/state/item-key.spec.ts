import { buildItemKey } from './item-key';

describe('buildItemKey', () => {
  it('names the canonical item when the entry has one, so every copy shares a key', () => {
    expect(buildItemKey({ id: 1, canonicalItemId: 7 })).toBe('item:7');
    expect(buildItemKey({ id: 2, canonicalItemId: 7 })).toBe('item:7');
  });

  it('names the entry when it has no canonical item', () => {
    expect(buildItemKey({ id: 1, canonicalItemId: null })).toBe('entry:1');
    expect(buildItemKey({ id: 2, canonicalItemId: null })).toBe('entry:2');
  });

  it('treats a missing canonical item id like none', () => {
    expect(buildItemKey({ id: 3 })).toBe('entry:3');
  });

  it('keeps an entry id apart from an item id of the same number', () => {
    expect(buildItemKey({ id: 7, canonicalItemId: null })).not.toBe(
      buildItemKey({ id: 1, canonicalItemId: 7 }),
    );
  });
});
