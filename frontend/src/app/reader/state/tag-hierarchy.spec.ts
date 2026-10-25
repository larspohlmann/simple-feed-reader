import { makeTag } from '../testing/tag.factory';
import { TagHierarchy } from './tag-hierarchy';

describe('TagHierarchy', () => {
  const reading = makeTag(1, 'Reading');
  const longReads = makeTag(2, 'Long reads', { parentId: 1 });
  const essays = makeTag(3, 'Essays', { parentId: 2 });
  const tech = makeTag(4, 'Tech');
  const books = makeTag(5, 'Books');
  const novels = makeTag(6, 'Novels', { parentId: 5 });
  const hierarchy = new TagHierarchy([reading, longReads, essays, tech, books, novels]);

  it('puts a root at depth 0 and adds one per level', () => {
    expect([1, 2, 3].map((id) => hierarchy.depthOf(id))).toEqual([0, 1, 2]);
  });

  it('measures the deepest branch below a tag', () => {
    expect([1, 2, 3].map((id) => hierarchy.heightOf(id))).toEqual([2, 1, 0]);
  });

  it('counts a tag within itself and its ancestors only', () => {
    expect(hierarchy.isWithin(3, 1)).toBe(true);
    expect(hierarchy.isWithin(3, 3)).toBe(true);
    expect(hierarchy.isWithin(1, 3)).toBe(false);
    expect(hierarchy.isWithin(3, 4)).toBe(false);
  });

  it('places a leaf two levels down but not three', () => {
    expect(hierarchy.mayPlaceUnder(4, 2)).toBe(true);
    expect(hierarchy.mayPlaceUnder(4, 3)).toBe(false);
  });

  it('places a tag with children only where its subtree still fits', () => {
    expect(hierarchy.mayPlaceUnder(5, 1)).toBe(true);
    expect(hierarchy.mayPlaceUnder(5, 2)).toBe(false);
  });

  it('never places a tag under itself or anything below it', () => {
    expect(hierarchy.mayPlaceUnder(1, 1)).toBe(false);
    expect(hierarchy.mayPlaceUnder(1, 2)).toBe(false);
  });

  it('always places at the top level, and a new tag anywhere with room below', () => {
    expect(hierarchy.mayPlaceUnder(3, null)).toBe(true);
    expect(hierarchy.mayPlaceUnder(null, 2)).toBe(true);
    expect(hierarchy.mayPlaceUnder(null, 3)).toBe(false);
  });

  it('treats a tag whose parent is unknown as a root', () => {
    const orphan = makeTag(7, 'Orphan', { parentId: 99 });
    expect(new TagHierarchy([orphan]).depthOf(7)).toBe(0);
  });
});
