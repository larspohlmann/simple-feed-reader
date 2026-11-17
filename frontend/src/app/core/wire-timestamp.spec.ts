import { wireTimestamp } from './wire-timestamp';

describe('wireTimestamp', () => {
  it('writes the moment in RFC 3339 at second precision, as the API parses it strictly', () => {
    expect(wireTimestamp(new Date('2026-11-16T08:41:18.884Z'))).toBe('2026-11-16T08:41:18Z');
  });

  it('keeps a whole second unchanged', () => {
    expect(wireTimestamp(new Date('2026-11-16T08:41:18.000Z'))).toBe('2026-11-16T08:41:18Z');
  });
});
