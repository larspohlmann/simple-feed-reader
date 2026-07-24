import { ListScrollStore, listScrollKey } from './list-scroll.store';
import { Selection } from './query';

const sel = (over: Partial<Selection> = {}): Selection => ({
  kind: 'all',
  id: null,
  unread: true,
  ...over,
});

describe('listScrollKey', () => {
  it('is stable for the same selection', () => {
    expect(listScrollKey(sel())).toBe(listScrollKey(sel()));
  });

  it('distinguishes kind, id and the unread filter', () => {
    const keys = new Set([
      listScrollKey(sel({ kind: 'all' })),
      listScrollKey(sel({ kind: 'tag', id: 3 })),
      listScrollKey(sel({ kind: 'tag', id: 4 })),
      listScrollKey(sel({ kind: 'subscription', id: 3 })),
      listScrollKey(sel({ kind: 'all', unread: false })),
    ]);
    expect(keys.size).toBe(5);
  });
});

describe('ListScrollStore', () => {
  it('returns 0 for an unknown key', () => {
    expect(new ListScrollStore().restore('nope')).toBe(0);
  });

  it('round-trips a saved position per key', () => {
    const store = new ListScrollStore();
    store.save('a', 420);
    store.save('b', 90);
    expect(store.restore('a')).toBe(420);
    expect(store.restore('b')).toBe(90);
  });

  it('overwrites the position for a key on re-save', () => {
    const store = new ListScrollStore();
    store.save('a', 420);
    store.save('a', 10);
    expect(store.restore('a')).toBe(10);
  });
});
