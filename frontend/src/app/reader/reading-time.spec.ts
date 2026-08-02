import { estimateReadingMinutes } from './reading-time';

const words = (n: number): string => Array.from({ length: n }, (_, i) => `word${i}`).join(' ');

describe('estimateReadingMinutes', () => {
  it('returns null for empty input', () => {
    expect(estimateReadingMinutes('')).toBeNull();
    expect(estimateReadingMinutes('   ')).toBeNull();
  });

  it('returns null for markup-only input', () => {
    expect(estimateReadingMinutes('<p></p><img src="x.jpg">')).toBeNull();
  });

  it('returns null below half a minute of text', () => {
    // 100 words / 220 wpm rounds to 0 minutes.
    expect(estimateReadingMinutes(`<p>${words(100)}</p>`)).toBeNull();
  });

  it('rounds to the nearest minute', () => {
    expect(estimateReadingMinutes(`<p>${words(220)}</p>`)).toBe(1);
    expect(estimateReadingMinutes(`<p>${words(550)}</p>`)).toBe(3);
  });

  it('does not count tags or entities as words', () => {
    const html = `<div class="wrapper"><p>${words(220)}</p>&nbsp;&amp;</div>`;
    expect(estimateReadingMinutes(html)).toBe(1);
  });
});
