/** The languages a feed can be read in; its headlines are stemmed by them in Trending (#2227). */
export const FEED_LANGUAGES = ['de', 'en', 'fr', 'es'] as const;
export type FeedLanguage = (typeof FEED_LANGUAGES)[number];

/** Clears a feed's language, so its next refresh guesses it again. */
export const AUTOMATIC_LANGUAGE = 'automatic';
export type FeedLanguageChoice = FeedLanguage | typeof AUTOMATIC_LANGUAGE;

export function feedLanguageNameKey(language: FeedLanguage): string {
  return `reader.feedLanguage.${language}`;
}
