import { randomUuid } from './random-uuid';

describe('randomUuid', () => {
  it('makes a version 4 UUID, fresh each time', () => {
    const first = randomUuid();

    expect(first).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(randomUuid()).not.toBe(first);
  });

  it('works where the page is not a secure context and crypto.randomUUID is missing', () => {
    const randomUUID = jest.spyOn(crypto, 'randomUUID');
    randomUUID.mockImplementation(() => {
      throw new TypeError('crypto.randomUUID is not a function');
    });

    expect(randomUuid()).toHaveLength(36);
    randomUUID.mockRestore();
  });
});
