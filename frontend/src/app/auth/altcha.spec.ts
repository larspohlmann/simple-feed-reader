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

  // The solver works in batches; a solution beyond the first batch, or exactly
  // on a boundary, must still be found -- an off-by-one in the batch
  // arithmetic would silently skip candidates and fail registration.
  it.each([0, 1, 255, 256, 257, 600])('finds the solution at n=%i', async (number) => {
    const salt = 'batch?expires=999';
    const challenge = await sha256hex(salt + number);
    const payload = await solveAltcha({
      algorithm: 'SHA-256',
      challenge,
      salt,
      signature: 'sig',
      maxnumber: 1000,
    });

    expect(JSON.parse(atob(payload)).number).toBe(number);
  });

  // A per-candidate `await` used to flood the microtask queue, which drains
  // before the browser paints -- froze the page (0.7s desktop, worse on
  // iPhone). This pins the fix: batching candidates and yielding between them.
  it('checks in periodically rather than once per candidate', async () => {
    const salt = 'slicing?expires=999';
    const number = 9000;
    const challenge = await sha256hex(salt + number);
    const ticks: number[] = [];

    await solveAltcha(
      { algorithm: 'SHA-256', challenge, salt, signature: 'sig', maxnumber: 10000 },
      (tried) => ticks.push(tried),
    );

    // More than one check-in (so the loop really is interleaved), but far fewer
    // than one per candidate (so the microtask flood that froze the page is
    // gone).
    expect(ticks.length).toBeGreaterThan(1);
    expect(ticks.length).toBeLessThan(number / 100);
  });

  it('reports progress so the UI can show more than a spinner', async () => {
    const salt = 'progress?expires=999';
    const number = 7000;
    const challenge = await sha256hex(salt + number);
    const seen: number[] = [];

    await solveAltcha(
      { algorithm: 'SHA-256', challenge, salt, signature: 'sig', maxnumber: 10000 },
      (tried) => seen.push(tried),
    );

    expect(seen.length).toBeGreaterThan(0);
    // Monotonic, and never claims to have tried more than it did.
    expect(seen).toEqual([...seen].sort((a, b) => a - b));
    expect(seen[seen.length - 1]).toBeLessThanOrEqual(number + 1);
  });
});
