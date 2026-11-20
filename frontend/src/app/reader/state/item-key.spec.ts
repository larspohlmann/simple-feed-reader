import { itemKey } from './item-key';

describe('itemKey', () => {
  it('names the canonical item when the entry has one, so every copy shares a key', () => {
    expect(itemKey({ id: 1, canonicalItemId: 7 })).toBe('item:7');
    expect(itemKey({ id: 2, canonicalItemId: 7 })).toBe('item:7');
  });

  it('names the entry when it has no canonical item', () => {
    expect(itemKey({ id: 1, canonicalItemId: null })).toBe('entry:1');
    expect(itemKey({ id: 2, canonicalItemId: null })).toBe('entry:2');
  });

  it('keeps an entry id apart from an item id of the same number', () => {
    expect(itemKey({ id: 7, canonicalItemId: null })).not.toBe(
      itemKey({ id: 1, canonicalItemId: 7 }),
    );
  });
});
