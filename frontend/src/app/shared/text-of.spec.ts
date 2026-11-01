import { textOf } from './text-of';

describe('textOf', () => {
  it('reads the text of HTML without its markup', () => {
    expect(textOf('<p> Is it <b>true</b>? </p>')).toBe('Is it true?');
  });

  it('reads nothing from no HTML', () => {
    expect(textOf(null)).toBe('');
    expect(textOf('')).toBe('');
  });
});
