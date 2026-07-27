import { planMagazine } from './magazine-planner';
import { MagazineBlock } from './magazine-block';
import { EntryDto } from '../models';

const e = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id,
  title: 'A headline of reasonable length',
  url: null,
  author: null,
  summary: 'A snippet long enough to fill a quote slot when one is asked for.',
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'S',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});
const big = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  e(id, { imageUrl: `https://i/${id}.jpg`, imageWidth: 900, imageHeight: 600, ...over });

const many = (n: number, make: (i: number) => EntryDto): EntryDto[] =>
  Array.from({ length: n }, (_, i) => make(i + 1));
const kinds = (bs: MagazineBlock[]) => bs.map((b) => b.kind);

const entryCount = (bs: MagazineBlock[]): number =>
  bs.reduce((n, b) => n + (b.kind === 'group' ? b.entries.length + b.moreCount : 1), 0);

const trigramEntropy = (ks: string[]): number => {
  const counts = new Map<string, number>();
  for (let i = 0; i + 2 < ks.length; i++) {
    const g = ks.slice(i, i + 3).join('>');
    counts.set(g, (counts.get(g) ?? 0) + 1);
  }
  const total = [...counts.values()].reduce((a, b) => a + b, 0);
  return -[...counts.values()].reduce((a, v) => a + (v / total) * Math.log2(v / total), 0);
};

describe('planMagazine', () => {
  it('emits every entry exactly once — nothing is ever hidden', () => {
    const entries = many(120, (i) => big(i, { subscriptionId: (i % 7) + 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(entryCount(blocks)).toBe(120);
  });

  it('is prefix-stable when more entries arrive', () => {
    const entries = many(120, (i) => big(i, { subscriptionId: (i % 7) + 1 }));
    const first = planMagazine({ entries: entries.slice(0, 60), grouping: true, complete: false });
    const second = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(second).slice(0, first.length)).toEqual(kinds(first));
  });

  it('preserves reverse-chronological order', () => {
    const entries = many(60, (i) => big(i, { subscriptionId: (i % 5) + 1 }));
    const ids = planMagazine({ entries, grouping: false, complete: true }).flatMap((b) =>
      b.kind === 'group' ? b.entries.map((x) => x.id) : [b.entry.id],
    );
    expect(ids).toEqual([...ids].sort((a, b) => a - b));
  });

  it('keeps 3-gram entropy above the boredom floor', () => {
    const entries = many(200, (i) => big(i, { subscriptionId: (i % 9) + 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(trigramEntropy(kinds(blocks))).toBeGreaterThan(4);
  });

  it('emits no image block when no entry has an image', () => {
    const entries = many(80, (i) => e(i, { subscriptionId: (i % 6) + 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('hero');
    expect(kinds(blocks)).not.toContain('wide');
    expect(kinds(blocks)).not.toContain('split');
    expect(kinds(blocks)).not.toContain('thumb');
  });

  it('does not group when one source dominates the view', () => {
    const entries = many(40, (i) => big(i, { subscriptionId: 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('group');
    expect(entryCount(blocks)).toBe(40);
  });

  it('groups a minority source and bounds what the digest consumes', () => {
    const entries = [
      ...many(30, (i) => big(i, { subscriptionId: (i % 6) + 2 })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Burst' })),
      ...many(30, (i) => big(200 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    expect(group!.kind === 'group' && group!.entries.length).toBeLessThanOrEqual(3);
    expect(entryCount(blocks)).toBe(68);
  });

  it('holds back a partial trailing page while more can load', () => {
    const entries = many(20, (i) => big(i, { subscriptionId: (i % 5) + 1 }));
    const held = planMagazine({ entries, grouping: true, complete: false });
    const done = planMagazine({ entries, grouping: true, complete: true });
    expect(held.length).toBeLessThanOrEqual(done.length);
    expect(entryCount(done)).toBe(20);
  });
});
