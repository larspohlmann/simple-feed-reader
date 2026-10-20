import { signedUrlFromParam, linkParam, sameSignedUrl } from './link-param';
import { A_READER_TOKEN } from '../../../testing/entry-testing';

describe('link param', () => {
  const signedUrl = { url: 'https://news.test/a~b?c=1', token: A_READER_TOKEN };

  it('round-trips a signed url, a tilde in the url included', () => {
    expect(signedUrlFromParam(linkParam(signedUrl))).toEqual(signedUrl);
  });

  it('round-trips a url whose scheme the server accepts in any case', () => {
    const upper = { url: 'HTTPS://News.test/A', token: A_READER_TOKEN };

    expect(signedUrlFromParam(linkParam(upper))).toEqual(upper);
  });

  it.each([
    null,
    '',
    '514.2',
    `${A_READER_TOKEN}`,
    `${A_READER_TOKEN}~`,
    `${A_READER_TOKEN}~javascript:alert(1)`,
    `${A_READER_TOKEN}~/relative`,
  ])('reads %p as no link', (value) => {
    expect(signedUrlFromParam(value)).toBeNull();
  });

  it('compares by value', () => {
    expect(sameSignedUrl({ ...signedUrl }, { ...signedUrl })).toBe(true);
    expect(sameSignedUrl(signedUrl, { ...signedUrl, url: 'https://news.test/other' })).toBe(false);
    expect(sameSignedUrl(null, null)).toBe(true);
  });
});
