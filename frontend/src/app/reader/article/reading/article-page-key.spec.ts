import { entryPageKey } from './article-page-key';

describe('entryPageKey', () => {
  it('keys an entry page by its id', () => {
    expect(entryPageKey(514)).toBe('entry:514');
  });
});
