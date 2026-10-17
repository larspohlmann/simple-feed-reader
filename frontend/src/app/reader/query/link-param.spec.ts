import { linkCardFromParam, linkParam, sameLinkCard } from './link-param';

describe('link param', () => {
  it('round-trips an entry id and card position', () => {
    expect(linkCardFromParam(linkParam({ entryId: 514, position: 2 }))).toEqual({
      entryId: 514,
      position: 2,
    });
  });

  it.each([null, '', '514', '514.', '.2', '0.1', '514.-1', '514.2.3', 'abc'])(
    'reads %p as no link',
    (value) => {
      expect(linkCardFromParam(value)).toBeNull();
    },
  );

  it('compares by value', () => {
    expect(sameLinkCard({ entryId: 1, position: 0 }, { entryId: 1, position: 0 })).toBe(true);
    expect(sameLinkCard({ entryId: 1, position: 0 }, { entryId: 1, position: 1 })).toBe(false);
    expect(sameLinkCard(null, null)).toBe(true);
  });
});
