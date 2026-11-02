import { entryPageKey, linkPageKey } from './article-page-key';

describe('entryPageKey', () => {
  it('keys an entry page by its id', () => {
    expect(entryPageKey(514)).toBe('entry:514');
  });
});

describe('linkPageKey', () => {
  it('keys a link page by its url', () => {
    expect(linkPageKey('https://news.test/story')).toBe('link:https://news.test/story');
  });
});
