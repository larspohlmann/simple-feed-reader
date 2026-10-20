import { anEntry, aLinkCard } from '../../../testing/entry-testing';
import { linkCardWithUrl } from './link-card-with-url';

describe('linkCardWithUrl', () => {
  const root = aLinkCard({ url: 'https://root.test/', title: 'Root' });
  const reply = aLinkCard({ url: 'https://reply.test/', title: 'Reply' });
  const entries = [
    anEntry({ id: 1 }),
    anEntry({
      id: 2,
      linkCards: [root],
      thread: [{ url: null, publishedAt: null, html: '', text: '', media: [], linkCard: reply }],
    }),
  ];

  it('finds a root card', () => {
    expect(linkCardWithUrl(entries, 'https://root.test/')?.title).toBe('Root');
  });

  it('finds a reply’s card', () => {
    expect(linkCardWithUrl(entries, 'https://reply.test/')?.title).toBe('Reply');
  });

  it('is null for a url no listed entry carries', () => {
    expect(linkCardWithUrl(entries, 'https://elsewhere.test/')).toBeNull();
  });
});
