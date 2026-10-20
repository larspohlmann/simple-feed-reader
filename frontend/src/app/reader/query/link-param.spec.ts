import { linkCardFromParam, linkParam, refOf, sameLinkCard } from './link-param';
import { aLinkCard, A_READER_TOKEN } from '../../../testing/entry-testing';

describe('link param', () => {
  const ref = { url: 'https://news.test/a~b?c=1', token: A_READER_TOKEN };

  it('round-trips a signed url, a tilde in the url included', () => {
    expect(linkCardFromParam(linkParam(ref))).toEqual(ref);
  });

  it('reads a card as its url and token', () => {
    expect(refOf(aLinkCard({ url: 'https://x.test/1' }))).toEqual({
      url: 'https://x.test/1',
      token: A_READER_TOKEN,
    });
  });

  it.each([
    null,
    '',
    '514.2',
    `${A_READER_TOKEN}`,
    `${A_READER_TOKEN}~`,
    `short~https://news.test/a`,
    `${A_READER_TOKEN}~javascript:alert(1)`,
    `${A_READER_TOKEN}~/relative`,
  ])('reads %p as no link', (value) => {
    expect(linkCardFromParam(value)).toBeNull();
  });

  it('compares by value', () => {
    expect(sameLinkCard({ ...ref }, { ...ref })).toBe(true);
    expect(sameLinkCard(ref, { ...ref, url: 'https://news.test/other' })).toBe(false);
    expect(sameLinkCard(null, null)).toBe(true);
  });
});
