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
// renders — this list is only a tidy picker). All match /^[a-z0-9_]+$/ and are
// verified to exist in the loaded font. Ordered by theme so the grid scans
// top-to-bottom: labels, reading, media, tech, work/finance, food, sport,
// travel, nature, comms/misc.
export const TAG_ICONS: string[] = [
  // Labels & general
  'label',
  'tag',
  'bookmark',
  'folder',
  'flag',
  'star',
  'favorite',
  'verified',
  // Reading & news
  'rss_feed',
  'feed',
  'newspaper',
  'article',
  'book',
  'menu_book',
  'language',
  'translate',
  // Media & art
  'podcasts',
  'headphones',
  'mic',
  'music_note',
  'movie',
  'theaters',
  'live_tv',
  'camera',
  'image',
  'palette',
  'brush',
  // Tech & science
  'code',
  'terminal',
  'devices',
  'computer',
  'smartphone',
  'cloud',
  'database',
  'bug_report',
  'rocket_launch',
  'lightbulb',
  'psychology',
  'science',
  'biotech',
  // Work, data & finance
  'analytics',
  'show_chart',
  'trending_up',
  'work',
  'business_center',
  'school',
  'gavel',
  'attach_money',
  'payments',
  'savings',
  'account_balance',
  'credit_card',
  'currency_bitcoin',
  // Food & drink
  'restaurant',
  'local_cafe',
  'local_bar',
  'wine_bar',
  'local_pizza',
  'cake',
  'celebration',
  // Sport & play
  'sports_esports',
  'sports_soccer',
  'sports_basketball',
  'sports_tennis',
  'fitness_center',
  'directions_run',
  'hiking',
  'pedal_bike',
  'downhill_skiing',
  'surfing',
  'casino',
  'extension',
  // Travel & places
  'flight',
  'train',
  'directions_car',
  'directions_boat',
  'sailing',
  'travel_explore',
  'map',
  'place',
  'luggage',
  'beach_access',
  'home',
  'store',
  'museum',
  // Nature & weather
  'public',
  'eco',
  'forest',
  'park',
  'local_florist',
  'pets',
  'water_drop',
  'wb_sunny',
  'ac_unit',
  'umbrella',
  'local_fire_department',
  'recycling',
  // Comms, health & objects
  'mail',
  'chat',
  'forum',
  'notifications',
  'campaign',
  'health_and_safety',
  'medical_services',
  'self_improvement',
  'spa',
  'lock',
  'key',
  'shield',
  'diamond',
  'watch',
];
