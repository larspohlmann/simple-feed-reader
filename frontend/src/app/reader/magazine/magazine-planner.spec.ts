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
const portrait = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  e(id, { imageUrl: `https://i/${id}.jpg`, imageWidth: 900, imageHeight: 1600, ...over });
// A wire-service entry: the feed ships only a tiny thumbnail but long copy.
const wire = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  e(id, {
    imageUrl: `https://i/${id}.jpg`,
    imageWidth: 90,
    imageHeight: 90,
    summary: 'A wire-service summary long enough to fill a pull quote. '.repeat(8),
    ...over,
  });

const many = (n: number, make: (i: number) => EntryDto): EntryDto[] =>
  Array.from({ length: n }, (_, i) => make(i + 1));
const kinds = (bs: MagazineBlock[]) => bs.map((b) => b.kind);

const entryCount = (bs: MagazineBlock[]): number =>
  bs.reduce((n, b) => n + (b.kind === 'group' ? b.entries.length : 1), 0);

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

  it('never opens the list with a group digest', () => {
    // A leading minority-source run would otherwise be grouped into the first
    // block; a wall of headlines is a weak start.
    const entries = [
      ...many(8, (i) => big(i, { subscriptionId: 1, source: 'Burst' })),
      ...many(40, (i) => big(100 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(blocks[0].kind).not.toBe('group');
  });

  it('never stacks a portrait image above the text — no hero or wide', () => {
    // A portrait image belongs beside the text (split), not above it: a tall
    // image in a hero slot would own most of the screen.
    const entries = many(80, (i) => portrait(i, { subscriptionId: (i % 6) + 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('hero');
    expect(kinds(blocks)).not.toContain('wide');
    // The image survives — it lands on an image-beside block, not a text block.
    expect(kinds(blocks)).toContain('split');
    expect(entryCount(blocks)).toBe(80);
  });

  it('renders a text-forward rhythm for an image-poor, text-rich view', () => {
    // A wire service ships only tiny thumbnails and long copy. The image family
    // would collapse every large slot to one `thumb` — a uniform wall — so the
    // planner switches to the text family: pull-quotes and headline bands, with
    // the small thumbnail as an accent, never the whole page.
    const entries = many(80, (i) => wire(i, { subscriptionId: (i % 6) + 1 }));
    const ks = kinds(planMagazine({ entries, grouping: true, complete: true }));
    expect(ks).not.toContain('hero');
    expect(ks).not.toContain('wide');
    expect(ks).not.toContain('split');
    expect(ks).toContain('quote');
    expect(ks).toContain('kicker');
    const thumbShare = ks.filter((k) => k === 'thumb').length / ks.length;
    expect(thumbShare).toBeLessThan(0.6);
    expect(trigramEntropy(ks)).toBeGreaterThan(4);
  });

  it('keeps the image family for an image-poor but text-poor view, surfacing what images exist', () => {
    // A dev blog: short posts, most with no image, a quarter carrying a large
    // one. Image-poor rules out the image-rich path, but text-poor rules out the
    // text family too — its pull-quotes would demote to headlines and its
    // headline slots would HIDE the images that do exist. The image family's
    // adaptive fillers surface them instead.
    const entries = many(80, (i) =>
      i % 4 === 0 ? big(i, { subscriptionId: (i % 6) + 1 }) : e(i, { subscriptionId: (i % 6) + 1 }),
    );
    const ks = kinds(planMagazine({ entries, grouping: true, complete: true }));
    expect(ks.some((k) => k === 'hero' || k === 'wide' || k === 'split' || k === 'thumb')).toBe(
      true,
    );
  });

  it('leads with a nearby image when the newest entries have none', () => {
    // The three newest posts are image-less; the fourth carries a photo. The
    // reader should land on that photo, and nothing is lost.
    const entries = [e(1), e(2), e(3), big(4), big(5), big(6), big(7), big(8)];
    const blocks = planMagazine({ entries, grouping: false, complete: true });
    const first = blocks[0];
    expect(first.kind).not.toBe('group');
    expect(first.kind === 'group' ? null : first.entry.id).toBe(4);
    expect(['hero', 'wide', 'split']).toContain(first.kind);
    expect(entryCount(blocks)).toBe(8);
  });

  it('is prefix-stable when the opener leads with a pulled-up image', () => {
    // The lead-image reorder reads only the fixed head, so a partial first
    // render and the full one pull the same entry up and share a prefix.
    const entries = [e(1), e(2), ...many(118, (i) => big(200 + i))];
    const first = planMagazine({ entries: entries.slice(0, 60), grouping: true, complete: false });
    const full = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(full).slice(0, first.length)).toEqual(kinds(first));
  });

  it('keeps the chronological head when no image is within reach of the start', () => {
    // Seven image-less posts, then images — the first image is past the reach,
    // so the list opens in order rather than yanking a distant photo up.
    const entries = [...many(7, (i) => e(i)), ...many(20, (i) => big(100 + i))];
    const blocks = planMagazine({ entries, grouping: false, complete: true });
    const first = blocks[0];
    expect(first.kind === 'group' ? null : first.entry.id).toBe(1);
  });

  it('emits no image block when no entry has an image', () => {
    const entries = many(80, (i) => e(i, { subscriptionId: (i % 6) + 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('hero');
    expect(kinds(blocks)).not.toContain('wide');
    expect(kinds(blocks)).not.toContain('split');
    expect(kinds(blocks)).not.toContain('thumb');
  });

  it('does not collapse when the leading window is effectively single-source', () => {
    // Fewer than MIN_VIEW_SOURCES distinct sources in the leading window ->
    // collapse is disabled entirely, so a mono view renders flat and smooth.
    const entries = many(40, (i) => big(i, { subscriptionId: 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('group');
    expect(entryCount(blocks)).toBe(40);
  });

  it('collapses a qualifying run into a featured lead plus a tail-owning widget', () => {
    const entries = [
      ...many(30, (i) => big(i, { subscriptionId: (i % 6) + 2 })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Burst' })),
      ...many(30, (i) => big(200 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    // Widget owns the whole tail: run of 8, minus 3 featured, = 5 entries.
    expect(group!.kind === 'group' && group!.entries.length).toBe(5);
    expect(group!.kind === 'group' && group!.previewCount).toBe(4);
    // The 3 newest of the run led as normal blocks, before the widget.
    const groupIndex = blocks.indexOf(group!);
    const featured = blocks
      .slice(0, groupIndex)
      .filter((b) => b.kind !== 'group' && b.entry.source === 'Burst');
    expect(featured.length).toBe(3);
    expect(entryCount(blocks)).toBe(68);
  });

  it('holds back a partial trailing page while more can load', () => {
    const entries = many(20, (i) => big(i, { subscriptionId: (i % 5) + 1 }));
    const held = planMagazine({ entries, grouping: true, complete: false });
    const done = planMagazine({ entries, grouping: true, complete: true });
    expect(held.length).toBeLessThanOrEqual(done.length);
    expect(entryCount(done)).toBe(20);
  });

  it('is prefix-stable even when a front-loaded source crosses the dominance threshold', () => {
    // 30 of source 1 up front, then 90 of other sources. Source 1 is 50% of the
    // first 60 but 25% of all 120 — a whole-window dominance test would flip it.
    const entries = [
      ...many(30, (i) => big(i, { subscriptionId: 1, source: 'Burst' })),
      ...many(90, (i) => big(100 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const first = planMagazine({ entries: entries.slice(0, 60), grouping: true, complete: false });
    const full = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(full).slice(0, first.length)).toEqual(kinds(first));
  });

  it('never fills a wide or split block from an untrusted inline thumbnail', () => {
    const entries = many(40, (i) =>
      e(i, { subscriptionId: (i % 6) + 1, contentHtml: '<img src="https://i/thumb.jpg">' }),
    );
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('wide');
    expect(kinds(blocks)).not.toContain('split');
  });

  it('bounds the LOOK_AHEAD reorder to a single swap without losing entries', () => {
    // Entry 1 has no image (can't fill the tallest slot); entry 3 does and is
    // within LOOK_AHEAD (2), so the reorder should pull it forward while
    // everything else stays in place — and no entry is lost or duplicated.
    const lookAhead = 2;
    const entries = [e(1), big(2), big(3), big(4), big(5)];
    const blocks = planMagazine({ entries, grouping: false, complete: true });
    const ids = blocks.flatMap((b) =>
      b.kind === 'group' ? b.entries.map((x) => x.id) : [b.entry.id],
    );
    expect([...ids].sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5]);
    ids.forEach((id, position) => {
      expect(Math.abs(id - 1 - position)).toBeLessThanOrEqual(lookAhead);
    });
  });

  it('collapses a dominant source while the view stays mixed', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(12, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(6, (i) => big(200 + i, { subscriptionId: i + 2, source: `s${i + 2}` })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    expect(group!.kind === 'group' && group!.entries.length).toBe(9); // 12 - 3 featured
    expect(group!.kind === 'group' && group!.previewCount).toBe(4);
    expect(entryCount(blocks)).toBe(24);
  });

  it('leaves a run flat when the trailing window lacks two other sources', () => {
    // Only ONE other source ever follows the run -> not enough to surface.
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(10, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(10, (i) => big(200 + i, { subscriptionId: 2, source: 'Solo' })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('group');
    expect(entryCount(blocks)).toBe(26);
  });

  it('merges two same-source segments across a single foreign post', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(6, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(150, { subscriptionId: 9, source: 'Interloper' }),
      ...many(6, (i) => big(160 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(8, (i) => big(200 + i, { subscriptionId: i + 2, source: `s${i + 2}` })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    // Both 6-entry segments merge into one run of 12, minus 3 featured = 9.
    expect(group!.kind === 'group' && group!.entries.length).toBe(9);
    // The bridged foreign post is surfaced as its own block AFTER the widget.
    const groupIndex = blocks.indexOf(group!);
    const surfaced = blocks
      .slice(groupIndex + 1)
      .find((b) => b.kind !== 'group' && b.entry.id === 150);
    expect(surfaced).toBeDefined();
    expect(entryCount(blocks)).toBe(27);
  });

  it('does not merge across a gap of two foreign posts', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(150, { subscriptionId: 9, source: 'A' }),
      big(151, { subscriptionId: 10, source: 'B' }),
      ...many(8, (i) => big(200 + i, { subscriptionId: i + 2, source: `s${i + 2}` })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    // Run stops at the 2-post gap: 8 - 3 featured = 5, NOT merged past it.
    expect(group!.kind === 'group' && group!.entries.length).toBe(5);
    expect(entryCount(blocks)).toBe(24);
  });

  it('re-features each separate run of the same source', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(150, { subscriptionId: 9, source: 'A' }),
      big(151, { subscriptionId: 10, source: 'B' }),
      big(152, { subscriptionId: 11, source: 'C' }),
      ...many(8, (i) => big(160 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(6, (i) => big(200 + i, { subscriptionId: i + 2, source: `s${i + 2}` })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const groups = blocks.filter((b) => b.kind === 'group');
    expect(groups.length).toBe(2);
    // 6 + 8 + 3 (A/B/C) + 8 + 6 = 31 entries, all surfaced exactly once.
    expect(entryCount(blocks)).toBe(31);
  });

  it('holds a qualifying run back until its trailing window loads', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(200, { subscriptionId: 3, source: 's3' }),
      big(201, { subscriptionId: 4, source: 's4' }),
    ];
    const held = planMagazine({ entries, grouping: true, complete: false });
    const done = planMagazine({ entries, grouping: true, complete: true });
    expect(held.some((b) => b.kind === 'group')).toBe(false);
    expect(done.some((b) => b.kind === 'group')).toBe(true);
  });

  it('is prefix-stable when a collapsing run’s page grows', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(12, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(102, (i) => big(200 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const first = planMagazine({ entries: entries.slice(0, 60), grouping: true, complete: false });
    const full = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(full).slice(0, first.length)).toEqual(kinds(first));
  });

  it('emits every entry exactly once even when runs collapse and bridge', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(5, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(150, { subscriptionId: 9, source: 'X' }),
      ...many(5, (i) => big(160 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(8, (i) => big(200 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    // 6 + 5 + 1 (bridged X) + 5 + 8 = 25 entries, all surfaced exactly once.
    expect(entryCount(blocks)).toBe(25);
  });
});
