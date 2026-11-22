import { postLength } from './post-length';

describe('postLength', () => {
  it('counts a Bluesky emoji family as one character', () => {
    expect(postLength('hi 👨‍👩‍👧', 'bluesky')).toBe(4);
  });

  it('counts a Bluesky link at its full length', () => {
    expect(postLength('https://example.test/a/long/path', 'bluesky')).toBe(32);
  });

  it('counts every Mastodon link as 23 characters', () => {
    expect(postLength('see https://example.test/a/long/path and http://x.test', 'mastodon')).toBe(
      'see  and '.length + 2 * 23,
    );
  });

  it('counts a Mastodon mention of a remote account by its username alone', () => {
    expect(postLength('@ann@hachyderm.io hi @bob', 'mastodon')).toBe('@ann hi @bob'.length);
  });

  it('counts Lemmy text in code points, links at their length', () => {
    expect(postLength('a😀', 'lemmy')).toBe(2);
    expect(postLength('https://example.test/a/long/path', 'lemmy')).toBe(32);
  });

  it('counts Mastodon text in graphemes, so an emoji with a skin tone is one', () => {
    expect(postLength('👍🏽', 'mastodon')).toBe(1);
    expect(postLength('é😀', 'mastodon')).toBe(2);
  });

  it('counts a Mastodon content warning as written, together with the text', () => {
    expect(postLength('a'.repeat(491), 'mastodon', 'spoilers!!')).toBe(501);
    expect(postLength('hi', 'mastodon', 'https://x.test')).toBe('https://x.testhi'.length);
  });
});
