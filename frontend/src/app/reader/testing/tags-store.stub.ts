import { WritableSignal, computed } from '@angular/core';
import { TagDto } from '../models';
import { tagsById } from '../state/tag-ancestry';
import { TagHierarchy } from '../state/tag-hierarchy';

export function tagsStoreStub(tags: WritableSignal<TagDto[]>) {
  const byIdMap = computed(() => tagsById(tags()));
  return {
    tags,
    hierarchy: computed(() => new TagHierarchy(tags())),
    tagsById: byIdMap,
    byId: (tagId: number) => byIdMap().get(tagId),
  };
}
