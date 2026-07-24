import { ListScrollMemory, scrollKey } from './list-scroll-memory';
import { Selection } from './query';

const sel = (over: Partial<Selection> = {}): Selection => ({
  kind: 'all',
  id: null,
  unread: true,
  ...over,
});

describe('scrollKey', () => {
  it('is distinct per kind, id and unread flag', () => {
    const keys = new Set([
      scrollKey(sel()),
      scrollKey(sel({ unread: false })),
      scrollKey(sel({ kind: 'tag', id: 3 })),
      scrollKey(sel({ kind: 'tag', id: 4 })),
      scrollKey(sel({ kind: 'subscription', id: 3 })),
    ]);
    expect(keys.size).toBe(5);
  });

  it('is stable for the same selection', () => {
    expect(scrollKey(sel({ kind: 'tag', id: 3 }))).toBe(scrollKey(sel({ kind: 'tag', id: 3 })));
  });
});

describe('ListScrollMemory', () => {
  let mem: ListScrollMemory;

  beforeEach(() => {
    sessionStorage.clear();
    mem = new ListScrollMemory();
  });

  it('round-trips an offset per selection and survives a fresh instance (a page reload)', () => {
    mem.save(sel(), 640);
    // A brand-new instance stands in for the app rebooting after an iOS/Brave
    // resume-reload — sessionStorage persists across it, an in-memory Map would not.
    expect(new ListScrollMemory().read(sel())).toBe(640);
  });

  it('reads 0 for a selection it has never seen', () => {
    expect(mem.read(sel({ kind: 'tag', id: 9 }))).toBe(0);
  });

  it("does not leak one selection's offset to another", () => {
    mem.save(sel({ kind: 'tag', id: 3 }), 500);
    expect(mem.read(sel({ kind: 'tag', id: 4 }))).toBe(0);
  });

  it('rounds and clamps on save; treats garbage and non-positive values as 0', () => {
    mem.save(sel(), 12.7);
    expect(mem.read(sel())).toBe(13);

    mem.save(sel({ kind: 'tag', id: 1 }), -5);
    expect(mem.read(sel({ kind: 'tag', id: 1 }))).toBe(0);

    sessionStorage.setItem(scrollKey(sel({ kind: 'tag', id: 2 })), 'nope');
    expect(mem.read(sel({ kind: 'tag', id: 2 }))).toBe(0);
  });

  it('round-trips an article scroll offset per entry id, surviving a fresh instance', () => {
    mem.saveEntry(514, 1200);
    expect(new ListScrollMemory().readEntry(514)).toBe(1200);
    expect(mem.readEntry(999)).toBe(0);
  });

  it('keeps article and list offsets in separate keys', () => {
    mem.save(sel({ kind: 'subscription', id: 5 }), 300);
    mem.saveEntry(5, 900);
    expect(mem.read(sel({ kind: 'subscription', id: 5 }))).toBe(300);
    expect(mem.readEntry(5)).toBe(900);
  });

  it('never throws when storage access is blocked (private mode / quota)', () => {
    const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const getItem = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    expect(() => mem.save(sel(), 100)).not.toThrow();
    expect(mem.read(sel())).toBe(0);
    setItem.mockRestore();
    getItem.mockRestore();
  });
});
