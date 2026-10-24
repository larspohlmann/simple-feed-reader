import { TagDto } from './models';
import { TagDropLayout, resolveTagDrop } from './tag-drop';

const tag = (id: number, parentId: number | null = null, position = 0): TagDto => ({
  id,
  name: `t${id}`,
  color: null,
  icon: null,
  position,
  parentId,
});
const tagA = tag(1, null, 0);
const tagB = tag(2, null, 1);
const tagC = tag(3, null, 2);
const B1 = tag(21, 2, 0);
const B2 = tag(22, 2, 1);
const TAGS = [tagA, tagB, tagC, B1, B2];

/** Each header 40 tall starting at index * 50: A 0–40, B 50–90, B1 100–140, B2 150–190, C 200–240. */
function layout(ids: number[] = [1, 2, 21, 22, 3]): TagDropLayout {
  const headers = ids.map((tagId, index) => ({ tagId, top: index * 50, bottom: index * 50 + 40 }));
  return { headers, listBottom: headers[headers.length - 1].bottom };
}

describe('resolveTagDrop', () => {
  it('reorders top-level tags from a top edge', () => {
    expect(resolveTagDrop(tagC, TAGS, layout(), 2)).toEqual({
      parentId: null,
      order: [3, 1, 2],
      mark: { kind: 'before', tagId: 1 },
    });
  });

  it('nests a childless top-level tag from the middle of another, placed last', () => {
    expect(resolveTagDrop(tagC, TAGS, layout(), 70)).toEqual({
      parentId: 2,
      order: [21, 22, 3],
      mark: { kind: 'nest', tagId: 2 },
    });
  });

  it('nests under a top-level tag that has no children yet', () => {
    expect(resolveTagDrop(tagC, TAGS, layout(), 20)).toEqual({
      parentId: 1,
      order: [3],
      mark: { kind: 'nest', tagId: 1 },
    });
  });

  it('makes the bottom edge of a parent with shown children its first child', () => {
    expect(resolveTagDrop(tagA, TAGS, layout(), 88)).toEqual({
      parentId: 2,
      order: [1, 21, 22],
      mark: { kind: 'before', tagId: 21 },
    });
  });

  it('places after a collapsed parent at the top level from its bottom edge', () => {
    expect(resolveTagDrop(tagA, TAGS, layout([1, 2, 3]), 88)).toEqual({
      parentId: null,
      order: [2, 1, 3],
      mark: { kind: 'after', tagId: 2 },
    });
  });

  it('reorders children inside one parent', () => {
    expect(resolveTagDrop(B2, TAGS, layout(), 105)).toEqual({
      parentId: 2,
      order: [22, 21],
      mark: { kind: 'before', tagId: 21 },
    });
  });

  it('un-nests a child to a chosen top-level spot', () => {
    expect(resolveTagDrop(B1, TAGS, layout(), 2)).toEqual({
      parentId: null,
      order: [21, 1, 2, 3],
      mark: { kind: 'before', tagId: 1 },
    });
  });

  it("un-nests a child from its own parent's top edge", () => {
    expect(resolveTagDrop(B1, TAGS, layout(), 52)).toEqual({
      parentId: null,
      order: [1, 21, 2, 3],
      mark: { kind: 'before', tagId: 2 },
    });
  });

  it('splits its own parent into halves instead of nesting again', () => {
    expect(resolveTagDrop(B2, TAGS, layout(), 75)).toEqual({
      parentId: 2,
      order: [22, 21],
      mark: { kind: 'before', tagId: 21 },
    });
  });

  it("moves a child to another parent, placed last, from that parent's middle", () => {
    const tags = [...TAGS, tag(11, 1, 0)];
    expect(resolveTagDrop(B1, tags, layout([1, 11, 2, 21, 22, 3]), 20)).toEqual({
      parentId: 1,
      order: [11, 21],
      mark: { kind: 'nest', tagId: 1 },
    });
  });

  it('moves a child to another parent at a chosen position', () => {
    const tags = [...TAGS, tag(11, 1, 0)];
    expect(resolveTagDrop(B1, tags, layout([1, 11, 2, 21, 22, 3]), 52)).toEqual({
      parentId: 1,
      order: [21, 11],
      mark: { kind: 'before', tagId: 11 },
    });
  });

  it('nests a top-level tag at a chosen position among children', () => {
    expect(resolveTagDrop(tagC, TAGS, layout(), 155)).toEqual({
      parentId: 2,
      order: [21, 3, 22],
      mark: { kind: 'before', tagId: 22 },
    });
  });

  it('places after the last child from its bottom edge', () => {
    expect(resolveTagDrop(tagA, TAGS, layout(), 185)).toEqual({
      parentId: 2,
      order: [21, 22, 1],
      mark: { kind: 'after', tagId: 22 },
    });
  });

  it('puts the tag at the end of the top level below the last block', () => {
    expect(resolveTagDrop(B1, TAGS, layout(), 300)).toEqual({
      parentId: null,
      order: [1, 2, 3, 21],
      mark: { kind: 'after', tagId: 3 },
    });
  });

  it('lets the nearest header decide when the pointer is over none', () => {
    // 46 is 4 px above B's top and 6 px below A's foot, so B's top edge applies.
    expect(resolveTagDrop(tagC, TAGS, layout(), 46)).toEqual({
      parentId: null,
      order: [1, 3, 2],
      mark: { kind: 'before', tagId: 2 },
    });
  });

  it('keeps a tag with children at the top level: no nest zone, no child slot', () => {
    expect(resolveTagDrop(tagB, TAGS, layout(), 5)).toEqual({
      parentId: null,
      order: [2, 1, 3],
      mark: { kind: 'before', tagId: 1 },
    });
    expect(resolveTagDrop(tagB, TAGS, layout(), 155)).toBeNull();
  });

  it('never makes a tag its own child', () => {
    expect(resolveTagDrop(tagB, TAGS, layout(), 105)).toBeNull();
  });

  it('resolves a drop that changes nothing to null', () => {
    expect(resolveTagDrop(tagA, TAGS, layout(), 2)).toBeNull();
    expect(resolveTagDrop(tagA, TAGS, layout(), 38)).toBeNull();
    expect(resolveTagDrop(B1, TAGS, layout(), 120)).toBeNull();
  });
});
