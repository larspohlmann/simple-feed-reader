// src/app/reader/manage/icon-choices.ts
// Data, not stylesheet colours: the Stylelint color-no-hex guard globs only
// *.scss. These are the values the backend's /^#[0-9a-fA-F]{6}$/ tag-colour
// rule accepts, offered as a quick palette next to a native colour input.
export const TAG_COLORS: string[] = [
  '#3f8676', // teal (accent)
  '#4f7cac', // blue
  '#5a9367', // green
  '#c08a3e', // amber
  '#b3403a', // rose
  '#8a6bbf', // violet
  '#6b7280', // slate
  '#b06a4f', // clay
  '#4c8ca3', // cyan
  '#a34c7a', // magenta
];

// Curated outlined Material Symbol names (the full font is loaded, so any name
// renders — this list is only a tidy picker). All match /^[a-z0-9_]+$/.
export const TAG_ICONS: string[] = [
  'label',
  'rss_feed',
  'newspaper',
  'code',
  'terminal',
  'science',
  'school',
  'work',
  'public',
  'trending_up',
  'bolt',
  'palette',
  'camera',
  'movie',
  'music_note',
  'sports_esports',
  'sports_soccer',
  'restaurant',
  'local_cafe',
  'flight',
  'shopping_cart',
  'favorite',
  'pets',
  'star',
];
