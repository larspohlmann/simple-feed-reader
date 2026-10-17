import { aLinkCard, anEntry } from '../../../testing/entry-testing';
import { aThreadPost } from '../../../testing/thread-testing';
import { linkCardAt } from './link-card-at';

describe('linkCardAt', () => {
  it('finds a root card by its stored position, not its list index', () => {
    const second = aLinkCard({ url: 'https://b.test/', position: 1 });
    const entry = anEntry({ linkCards: [second] });

    expect(linkCardAt(entry, 1)).toBe(second);
    expect(linkCardAt(entry, 0)).toBeNull();
  });

  it('finds a reply card by its position when the replies come before the root cards', () => {
    const replyCard = aLinkCard({ url: 'https://reply.test/', position: 0 });
    const rootCard = aLinkCard({ url: 'https://root.test/', position: 1 });
    const entry = anEntry({
      linkCards: [rootCard],
      thread: [aThreadPost({ linkCard: replyCard })],
    });

    expect(linkCardAt(entry, 0)).toBe(replyCard);
    expect(linkCardAt(entry, 1)).toBe(rootCard);
  });

  it('gives null for a position no card holds', () => {
    const entry = anEntry({
      linkCards: [aLinkCard({ position: 2 })],
      thread: [aThreadPost({ linkCard: null })],
    });

    expect(linkCardAt(entry, 7)).toBeNull();
  });
});
