import { aLinkCard, anEntry, aStoryArticle } from '../../../testing/entry-testing';
import { articleFaceOf } from './shared-article-card';

describe('articleFaceOf', () => {
  it("keeps a card's description as stored", () => {
    const article = aStoryArticle({
      shares: [anEntry({ id: 9 })],
      linkCards: [aLinkCard({ description: 'https://x.example/a' })],
    });

    expect(articleFaceOf(article).dek).toBe('https://x.example/a');
  });

  it('drops an entry dek that is a bare URL', () => {
    const article = aStoryArticle({ excerpt: '  https://x.example/a ' });

    expect(articleFaceOf(article).dek).toBe('');
  });

  it('keeps an entry dek of words', () => {
    const article = aStoryArticle({ excerpt: 'Read https://x.example/a today' });

    expect(articleFaceOf(article).dek).toBe('Read https://x.example/a today');
  });
});
