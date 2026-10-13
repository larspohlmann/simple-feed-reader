import { initialsOf } from './social-import-rows';

describe('initialsOf', () => {
  it('takes the first letter of up to two words, without the handle sign', () => {
    expect(initialsOf('Ada Byrne')).toBe('AB');
    expect(initialsOf('Field Notes Weekly')).toBe('FN');
    expect(initialsOf('@fay.bsky.social')).toBe('F');
    expect(initialsOf('  jun  ')).toBe('J');
  });

  it('keeps a letter outside the basic plane whole', () => {
    expect(initialsOf('𝔄da')).toBe('𝔄');
  });
});
