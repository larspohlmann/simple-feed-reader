import { TagDto } from '../models';
import { INCLUDED_EVERYWHERE } from '../tag-inclusion';

export { INCLUDED_EVERYWHERE };

/** A plain `TagDto` for specs: a root tag positioned by its id unless `partial` says otherwise. */
export function makeTag(id: number, name: string, partial: Partial<TagDto> = {}): TagDto {
  return {
    id,
    name,
    color: null,
    icon: null,
    position: id,
    parentId: null,
    ...INCLUDED_EVERYWHERE,
    ...partial,
  };
}
