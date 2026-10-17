import { ReaderArticle, ReaderFailure } from '../app/reader/models';

/** An extracted reader article with nothing notable; `overrides` name what a test is about. */
export function aReaderArticle(overrides: Partial<ReaderArticle> = {}): ReaderArticle {
  return {
    status: 'ok',
    contentHtml: '<p>READER</p>',
    url: '',
    title: '',
    byline: null,
    siteName: null,
    excerpt: null,
    originalHero: null,
    extractedAt: '',
    paywalled: false,
    ...overrides,
  };
}

/** A failed extraction with no detail and no hero. */
export function aReaderFailure(overrides: Partial<ReaderFailure> = {}): ReaderFailure {
  return {
    status: 'failed',
    reason: 'fetch',
    detail: null,
    url: null,
    originalHero: null,
    ...overrides,
  };
}
