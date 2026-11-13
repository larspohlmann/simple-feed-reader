import { SURFACE_DESCRIPTORS } from './feed-exclusions';
import type { TagInclusion } from './models';

/** A tag's default: in every surface. */
export const INCLUDED_EVERYWHERE: Readonly<TagInclusion> = Object.fromEntries(
  SURFACE_DESCRIPTORS.map(({ flag }) => [flag, true]),
) as Record<keyof TagInclusion, boolean>;
