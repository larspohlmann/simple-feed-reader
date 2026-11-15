import { aLinkCard, anEntry } from '../../../testing/entry-testing';
import { articleFaceOf } from './shared-article-card';

describe('articleFaceOf', () => {
  it("keeps a card's description as stored", () => {
    const entry = anEntry({
      shares: [anEntry({ id: 9 })],
      linkCards: [aLinkCard({ description: 'https://x.example/a' })],
    });

    expect(articleFaceOf(entry).dek).toBe('https://x.example/a');
  });

  it('drops an entry dek that is a bare URL', () => {
    const entry = anEntry({ shares: [], excerpt: '  https://x.example/a ' });

    expect(articleFaceOf(entry).dek).toBe('');
  });

  it('keeps an entry dek of words', () => {
    const entry = anEntry({ shares: [], excerpt: 'Read https://x.example/a today' });

    expect(articleFaceOf(entry).dek).toBe('Read https://x.example/a today');
  });
});
