import { convertToParamMap } from '@angular/router';
import { ListScrollMemory, scrollKey } from './list-scroll-memory';
import { Selection, selectionFromParams, withListOrder } from '../query/query';

const ALL_UNREAD: Selection = { kind: 'all', unread: true };
const tag = (id: number): Selection => ({ kind: 'tag', id, unread: true });

describe('scrollKey', () => {
  it('is distinct per kind, id and unread flag', () => {
    const keys = new Set([
      scrollKey(ALL_UNREAD),
      scrollKey({ kind: 'all', unread: false }),
      scrollKey(tag(3)),
      scrollKey(tag(4)),
      scrollKey({ kind: 'subscription', id: 3, unread: true }),
    ]);
    expect(keys.size).toBe(5);
  });

  it('is stable for the same selection', () => {
    expect(scrollKey(tag(3))).toBe(scrollKey(tag(3)));
  });

  it('is distinct per search term', () => {
    const angular = scrollKey({ kind: 'search', unread: true, term: 'angular' });
    const react = scrollKey({ kind: 'search', unread: true, term: 'react' });
    expect(angular).not.toBe(react);
  });

  it('is distinct per list order', () => {
    expect(scrollKey(tag(3))).not.toBe(scrollKey(withListOrder(tag(3), 'oldest')));
  });

  it('is distinct per network and per network feed kind', () => {
    const keys = new Set([
      scrollKey({ kind: 'network', unread: true, network: 'bluesky' }),
      scrollKey({ kind: 'network', unread: true, network: 'bluesky', feedKind: 'profile' }),
      scrollKey({ kind: 'network', unread: true, network: 'mastodon' }),
    ]);
    expect(keys.size).toBe(3);
  });

  it('keeps the two feed groups apart', () => {
    expect(scrollKey({ kind: 'group', unread: true, group: 'tags' })).not.toBe(
      scrollKey({ kind: 'group', unread: true, group: 'feeds' }),
    );
  });

  it("keeps Trending's two windows apart", () => {
    expect(scrollKey({ kind: 'trending', window: 'day' })).not.toBe(
      scrollKey({ kind: 'trending', window: 'week' }),
    );
  });
});

describe('ListScrollMemory', () => {
  let mem: ListScrollMemory;

  beforeEach(() => {
    sessionStorage.clear();
    mem = new ListScrollMemory();
  });

  it('round-trips an offset per selection and survives a fresh instance (a page reload)', () => {
    mem.save(ALL_UNREAD, 640);
    // A brand-new instance stands in for the app rebooting after an iOS/Brave
    // resume-reload — sessionStorage persists across it, an in-memory Map would not.
    expect(new ListScrollMemory().read(ALL_UNREAD)).toBe(640);
  });

  it('reads 0 for a selection it has never seen', () => {
    expect(mem.read(tag(9))).toBe(0);
  });

  it('keeps saved-search and direct-search positions separate through a reload', () => {
    const saved: Selection = { kind: 'saved-search', id: 7, unread: false };
    const direct = selectionFromParams(convertToParamMap({ q: 'climate' }), {
      trending: true,
      trendingWindow: 'day',
    }).selection;
    mem.save(saved, 640);
    mem.save(direct, 120);

    const restored = new ListScrollMemory();
    expect(restored.read(saved)).toBe(640);
    expect(restored.read(direct)).toBe(120);
  });

  it("does not leak one selection's offset to another", () => {
    mem.save(tag(3), 500);
    expect(mem.read(tag(4))).toBe(0);
  });

  it('rounds and clamps on save; treats garbage and non-positive values as 0', () => {
    mem.save(ALL_UNREAD, 12.7);
    expect(mem.read(ALL_UNREAD)).toBe(13);

    mem.save(tag(1), -5);
    expect(mem.read(tag(1))).toBe(0);

    sessionStorage.setItem(scrollKey(tag(2)), 'nope');
    expect(mem.read(tag(2))).toBe(0);
  });

  it('forgets one list without touching another', () => {
    mem.save(tag(3), 500);
    mem.save(tag(4), 700);

    mem.forget(tag(3));

    expect(mem.read(tag(3))).toBe(0);
    expect(mem.read(tag(4))).toBe(700);
  });

  it('round-trips an article scroll offset per page key, surviving a fresh instance', () => {
    mem.saveArticle('entry:514', 1200);
    expect(new ListScrollMemory().readArticle('entry:514')).toBe(1200);
    expect(mem.readArticle('entry:999')).toBe(0);
  });

  it('keeps article and list offsets in separate keys', () => {
    const subscription: Selection = { kind: 'subscription', id: 5, unread: true };
    mem.save(subscription, 300);
    mem.saveArticle('entry:5', 900);
    expect(mem.read(subscription)).toBe(300);
    expect(mem.readArticle('entry:5')).toBe(900);
  });

  it('never throws when storage access is blocked (private mode / quota)', () => {
    const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const getItem = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const removeItem = jest.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    expect(() => mem.save(ALL_UNREAD, 100)).not.toThrow();
    expect(mem.read(ALL_UNREAD)).toBe(0);
    expect(() => mem.forget(ALL_UNREAD)).not.toThrow();
    setItem.mockRestore();
    getItem.mockRestore();
    removeItem.mockRestore();
  });
});
