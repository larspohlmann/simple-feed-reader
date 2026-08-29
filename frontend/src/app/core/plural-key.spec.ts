import { pluralKey } from './plural-key';

describe('pluralKey', () => {
  it('picks the One key at exactly one', () => {
    expect(pluralKey('manage.bulk.tagAdded', 1)).toBe('manage.bulk.tagAddedOne');
  });

  it('picks the Other key at zero', () => {
    expect(pluralKey('manage.bulk.tagAdded', 0)).toBe('manage.bulk.tagAddedOther');
  });

  it('picks the Other key above one', () => {
    expect(pluralKey('manage.bulk.tagAdded', 2)).toBe('manage.bulk.tagAddedOther');
  });
});
