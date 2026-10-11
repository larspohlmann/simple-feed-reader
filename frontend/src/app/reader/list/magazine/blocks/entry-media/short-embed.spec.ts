import { shortPlayerUrl } from './short-embed';

const PLAYER = 'https://www.youtube-nocookie.com/embed/87Ov3cS-xMs?autoplay=1#shorts';

describe('shortPlayerUrl', () => {
  it('is the allow-listed nocookie player of a Short, starting on load', () => {
    expect(shortPlayerUrl('https://www.youtube.com/shorts/87Ov3cS-xMs')).toBe(PLAYER);
    expect(shortPlayerUrl('https://m.youtube.com/shorts/87Ov3cS-xMs?feature=share')).toBe(PLAYER);
  });

  it('is null for any other URL', () => {
    expect(shortPlayerUrl('https://www.youtube.com/watch?v=87Ov3cS-xMs')).toBeNull();
    expect(shortPlayerUrl('https://evil.test/shorts/87Ov3cS-xMs')).toBeNull();
    expect(shortPlayerUrl(null)).toBeNull();
  });
});
