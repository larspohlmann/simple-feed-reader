import { WritableSignal, computed } from '@angular/core';
import { TagDto } from '../models';
import { TagHierarchy } from '../state/tag-hierarchy';

export function tagsStoreStub(tags: WritableSignal<TagDto[]>) {
  return { tags, hierarchy: computed(() => new TagHierarchy(tags())) };
}
