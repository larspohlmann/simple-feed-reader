import { TagDto } from '../models';
import { INCLUDED_EVERYWHERE } from '../testing/tag.factory';
import { tagsById, withAncestors } from './tag-ancestry';

const tag = (id: number, parentId: number | null): TagDto => ({
  id,
  name: `Tag ${id}`,
  color: null,
  icon: null,
  position: 0,
  parentId,
  ...INCLUDED_EVERYWHERE,
});

const ids = (tags: TagDto[]): number[] => tags.map(({ id }) => id);

describe('withAncestors', () => {
  it('walks from the tag up to the top-most ancestor', () => {
    const tags = [tag(1, null), tag(2, 1), tag(3, 2)];
    expect(ids(withAncestors(tags[2], tagsById(tags)))).toEqual([3, 2, 1]);
  });

  it('stops at a parent the list does not know', () => {
    const tags = [tag(2, 1), tag(3, 2)];
    expect(ids(withAncestors(tags[1], tagsById(tags)))).toEqual([3, 2]);
  });

  it('visits each tag once when parents loop', () => {
    const tags = [tag(1, 2), tag(2, 1)];
    expect(ids(withAncestors(tags[0], tagsById(tags)))).toEqual([1, 2]);
  });
});
