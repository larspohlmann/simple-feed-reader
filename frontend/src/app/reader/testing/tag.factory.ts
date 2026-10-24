import { TagDto } from '../models';

/** A plain `TagDto` for specs: a root tag positioned by its id unless `partial` says otherwise. */
export function makeTag(id: number, name: string, partial: Partial<TagDto> = {}): TagDto {
  return { id, name, color: null, icon: null, position: id, parentId: null, ...partial };
}
