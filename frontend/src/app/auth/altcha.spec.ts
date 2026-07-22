// src/app/auth/altcha.spec.ts
import { AltchaChallenge, solveAltcha } from './altcha';

async function sha256hex(input: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

describe('solveAltcha', () => {
  it('finds the number whose sha256(salt+n) matches and encodes the payload', async () => {
    const salt = 'abc?expires=999';
    const number = 7;
    const challenge = await sha256hex(salt + number);
    const c: AltchaChallenge = {
      algorithm: 'SHA-256',
      challenge,
      salt,
      signature: 'sig',
      maxnumber: 50,
    };

    const payload = await solveAltcha(c);
    const decoded = JSON.parse(atob(payload));
    expect(decoded).toEqual({
      algorithm: 'SHA-256',
      challenge,
      number: 7,
      salt,
      signature: 'sig',
    });
  });

  it('throws when the challenge is unsolvable within maxnumber', async () => {
    const c: AltchaChallenge = {
      algorithm: 'SHA-256',
      challenge: 'deadbeef',
      salt: 's',
      signature: 'x',
      maxnumber: 3,
    };
    await expect(solveAltcha(c)).rejects.toThrow();
  });
});
