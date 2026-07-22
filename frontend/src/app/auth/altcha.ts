// src/app/auth/altcha.ts
export interface AltchaChallenge {
  algorithm: string;
  challenge: string;
  salt: string;
  signature: string;
  maxnumber: number;
}

/** Brute-force the ALTCHA proof-of-work: find the smallest n≥0 whose
 *  sha256hex(salt+n) equals the challenge, then base64-encode the solution the
 *  backend's verify() expects. Costs the honest client measurable CPU; the
 *  backend enforces the difficulty floor. */
export async function solveAltcha(c: AltchaChallenge): Promise<string> {
  const enc = new TextEncoder();
  for (let n = 0; n <= c.maxnumber; n++) {
    const digest = await crypto.subtle.digest('SHA-256', enc.encode(c.salt + n));
    const hex = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
    if (hex === c.challenge) {
      return btoa(
        JSON.stringify({
          algorithm: c.algorithm,
          challenge: c.challenge,
          number: n,
          salt: c.salt,
          signature: c.signature,
        }),
      );
    }
  }
  throw new Error('ALTCHA challenge could not be solved');
}
