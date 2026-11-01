import { linkPrefill } from './link-prefill';

describe('linkPrefill', () => {
  const url = 'https://example.com/story';

  it('starts a Bluesky post with the title, as the card carries the link', () => {
    expect(linkPrefill('bluesky', 'A story', url)).toBe('A story');
  });

  it('starts a Mastodon post with the title and the link below it', () => {
    expect(linkPrefill('mastodon', 'A story', url)).toBe(`A story\n\n${url}`);
  });

  it('starts with the link alone on Mastodon and empty on Bluesky when there is no title', () => {
    expect(linkPrefill('mastodon', '', url)).toBe(url);
    expect(linkPrefill('bluesky', '', url)).toBe('');
  });
});
