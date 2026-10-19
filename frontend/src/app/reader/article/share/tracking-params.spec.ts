import { stripTracking } from './tracking-params';

describe('stripTracking', () => {
  it('drops every utm_ parameter and the listed click ids, keeping the rest and the fragment', () => {
    expect(
      stripTracking(
        'https://example.com/story?id=7&utm_source=rss&UTM_Medium=x&fbclid=a&gclid=b&dclid=c&msclkid=d' +
          '&mc_eid=e&mc_cid=f&igshid=g&yclid=h&_hsenc=i&_hsmi=j&ref_src=k#part',
      ),
    ).toBe('https://example.com/story?id=7#part');
  });

  it('leaves a URL without tracking untouched, encoding included', () => {
    expect(stripTracking('https://example.com/a?q=a+b&x=%2F')).toBe(
      'https://example.com/a?q=a+b&x=%2F',
    );
  });

  it('keeps the other parameters byte-identical', () => {
    expect(stripTracking('https://example.com/a?flag&q=a%20b&utm_source=x')).toBe(
      'https://example.com/a?flag&q=a%20b',
    );
  });

  it('drops the question mark when only tracking was there', () => {
    expect(stripTracking('https://example.com/a?utm_campaign=z')).toBe('https://example.com/a');
  });

  it('returns what it cannot parse as is', () => {
    expect(stripTracking('not a url')).toBe('not a url');
  });
});
