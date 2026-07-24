import { gravatarUrl, normalizeEmail, sha256Hex } from './gravatar';

describe('normalizeEmail', () => {
  it('trims and lower-cases', () => {
    expect(normalizeEmail('  Foo@Example.COM ')).toBe('foo@example.com');
  });
});

describe('sha256Hex', () => {
  it('produces the known SHA-256 vector for "abc"', async () => {
    expect(await sha256Hex('abc')).toBe(
      'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
    );
  });
});

describe('gravatarUrl', () => {
  it('builds a sized avatar URL that 404s when there is no avatar', () => {
    const url = gravatarUrl('deadbeef', 48);
    expect(url).toBe('https://www.gravatar.com/avatar/deadbeef?s=48&d=404');
  });
});
