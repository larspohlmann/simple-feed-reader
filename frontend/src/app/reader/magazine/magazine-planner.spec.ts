import { planMagazine, MagazineBlock } from './magazine-planner';
import { EntryDto } from '../models';

const e = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id,
  title: 't',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'S',
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});
const withImg = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  e(id, { contentHtml: `<img src="https://x/${id}.jpg">`, title: 'x'.repeat(130), ...over });
const kinds = (bs: MagazineBlock[]) => bs.map((b) => b.kind);

describe('planMagazine', () => {
  it('leads a same-source run with a hero and groups the rest', () => {
    const bs = planMagazine([e(1), e(2), e(3), e(4), e(5)], true);
    expect(bs).toHaveLength(2);
    expect(bs[0].kind).toBe('hero');
    expect((bs[0] as Extract<MagazineBlock, { kind: 'hero' }>).entry.id).toBe(1);
    expect(bs[1]).toMatchObject({ kind: 'group', subscriptionId: 1, source: 'S', moreCount: 1 });
    expect((bs[1] as Extract<MagazineBlock, { kind: 'group' }>).entries.map((x) => x.id)).toEqual([
      2, 3, 4,
    ]);
  });

  it('groups a run without a second, adjacent hero right after an ungrouped hero', () => {
    const bs = planMagazine(
      [e(1, { subscriptionId: 9, source: 'Z', title: 'x'.repeat(200) }), e(2), e(3), e(4)],
      true,
    );
    expect(bs[0].kind).toBe('hero');
    expect(bs[1].kind).toBe('group');
    expect((bs[1] as Extract<MagazineBlock, { kind: 'group' }>).entries.map((x) => x.id)).toEqual([
      2, 3, 4,
    ]);
    expect((bs[1] as Extract<MagazineBlock, { kind: 'group' }>).moreCount).toBe(0);
  });

  it('does not group a run of 2', () => {
    const bs = planMagazine([e(1), e(2), e(3, { subscriptionId: 9, source: 'B' })], true);
    expect(kinds(bs)).not.toContain('group');
    expect(bs).toHaveLength(3);
  });

  it('never groups in a single-subscription view (grouping=false)', () => {
    const bs = planMagazine([e(1), e(2), e(3), e(4)], false);
    expect(kinds(bs)).not.toContain('group');
    expect(bs).toHaveLength(4);
  });

  it('makes a text hero for image-less long text and a compact for short text', () => {
    const long = e(1, { subscriptionId: 1, source: 'A', title: 'x'.repeat(200) });
    const short = e(2, { subscriptionId: 2, source: 'B', title: 'hi' });
    const bs = planMagazine([long, short], true);
    expect(bs[0].kind).toBe('hero');
    expect(bs[1].kind).toBe('compact');
  });

  it('leads a source group with its first image-bearing entry', () => {
    // Run of 4 from one source; only the 2nd entry has an image. The big hero
    // slot should prefer that image entry, and the cluster keeps the rest in order.
    const bs = planMagazine(
      [
        e(1, { subscriptionId: 1, source: 'S' }),
        withImg(2, { subscriptionId: 1, source: 'S' }),
        e(3, { subscriptionId: 1, source: 'S' }),
        e(4, { subscriptionId: 1, source: 'S' }),
      ],
      true,
    );
    expect(bs[0].kind).toBe('hero');
    expect((bs[0] as Extract<MagazineBlock, { kind: 'hero' }>).entry.id).toBe(2);
    expect((bs[1] as Extract<MagazineBlock, { kind: 'group' }>).entries.map((x) => x.id)).toEqual([
      1, 3, 4,
    ]);
  });

  it('leads a source group with the first entry when none early have an image', () => {
    const bs = planMagazine([e(1), e(2), e(3), e(4)], true);
    expect((bs[0] as Extract<MagazineBlock, { kind: 'hero' }>).entry.id).toBe(1);
    expect((bs[1] as Extract<MagazineBlock, { kind: 'group' }>).entries.map((x) => x.id)).toEqual([
      2, 3, 4,
    ]);
  });

  it('spaces heroes by HERO_PERIOD and never places two adjacent', () => {
    const many = Array.from({ length: 8 }, (_, i) =>
      withImg(i + 1, { subscriptionId: i + 1, source: `S${i}` }),
    );
    const bs = planMagazine(many, true);
    const heroAt = bs.map((b, i) => (b.kind === 'hero' ? i : -1)).filter((i) => i >= 0);
    expect(heroAt[0]).toBe(0);
    expect(heroAt[1]).toBe(4);
    for (let i = 1; i < heroAt.length; i++) expect(heroAt[i] - heroAt[i - 1]).toBeGreaterThan(1);
  });

  it('alternates the feature image side', () => {
    const a = withImg(1, { subscriptionId: 1, source: 'A', title: 'short' });
    const b = withImg(2, { subscriptionId: 2, source: 'B', title: 'short' });
    const bs = planMagazine(
      [
        e(0, {
          subscriptionId: 9,
          source: 'Z',
          title: 'x'.repeat(200),
          contentHtml: '<img src="https://x/0.jpg">',
        }),
        a,
        b,
      ],
      true,
    );
    const feats = bs.filter((x) => x.kind === 'feature') as Extract<
      MagazineBlock,
      { kind: 'feature' }
    >[];
    expect(feats[0].imageSide).toBe('right');
    expect(feats[1].imageSide).toBe('left');
  });

  it('is a stable prefix when a later page of a different source is appended', () => {
    const a = [
      e(1, { subscriptionId: 1, source: 'A' }),
      e(2, { subscriptionId: 2, source: 'B' }),
      e(3, { subscriptionId: 3, source: 'C' }),
    ];
    const b = [e(4, { subscriptionId: 4, source: 'D' }), e(5, { subscriptionId: 5, source: 'E' })];
    const planA = planMagazine(a, true);
    const planAB = planMagazine(a.concat(b), true);
    expect(planAB.slice(0, planA.length)).toEqual(planA);
  });

  it('holds a short trailing same-source run while more pages can load, keeping the prefix stable', () => {
    // Page 1 ends with a run of 2 from source X (sub 7); more pages to come.
    const page1 = [
      e(1, { subscriptionId: 1, source: 'A' }),
      e(2, { subscriptionId: 2, source: 'B' }),
      e(3, { subscriptionId: 7, source: 'X' }),
      e(4, { subscriptionId: 7, source: 'X' }),
    ];
    const planned1 = planMagazine(page1, true, false); // complete=false → hold the X tail
    expect(planned1).toHaveLength(2); // only A and B emitted; the two X's are held
    expect(planned1.map((b) => b.kind)).not.toContain('group');

    // Page 2 extends the X run to 3 — it must now group WITHOUT rewriting A/B.
    const page2 = [e(5, { subscriptionId: 7, source: 'X' })];
    const planned2 = planMagazine(page1.concat(page2), true, false);
    expect(planned2.slice(0, planned1.length)).toEqual(planned1); // prefix stable
    expect(planned2.some((b) => b.kind === 'group')).toBe(true);
  });

  it('emits a held trailing run once the list is complete (last page)', () => {
    const entries = [
      e(1, { subscriptionId: 1, source: 'A' }),
      e(2, { subscriptionId: 7, source: 'X' }),
      e(3, { subscriptionId: 7, source: 'X' }),
    ];
    // complete=true → nothing held; the run of 2 X's renders as individual blocks.
    const planned = planMagazine(entries, true, true);
    expect(planned).toHaveLength(3);
    expect(planned.map((b) => b.kind)).not.toContain('group');
  });
});
