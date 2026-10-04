import { parseTextSize, TEXT_SIZE_DEFAULT, TEXT_SIZE_STEPS } from './text-size';

describe('parseTextSize', () => {
  it('accepts every step', () => {
    for (const step of TEXT_SIZE_STEPS) {
      expect(parseTextSize(String(step))).toBe(step);
    }
  });

  it.each([null, '', 'large', '95', '80', '160', '1.1'])('reads %p as the default', (raw) => {
    expect(parseTextSize(raw)).toBe(TEXT_SIZE_DEFAULT);
  });
});
