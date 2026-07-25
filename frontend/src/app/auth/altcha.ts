// src/app/auth/altcha.ts
import { sha256Hex } from './sha256';

export interface AltchaChallenge {
  algorithm: string;
  challenge: string;
  salt: string;
  signature: string;
  maxnumber: number;
}

/** Candidates to grind through before looking at the clock. Small enough that a
 *  slice never overruns noticeably, large enough that the check itself costs
 *  nothing against the hashing. */
const CHECK_EVERY = 2048;

/** How long to grind before handing the loop back, in milliseconds. Roughly a
 *  frame at 20fps: often enough that a spinner keeps turning and the page stays
 *  responsive, rare enough that the timer overhead stays negligible. */
const SLICE_MS = 50;

/** Hands control back to the event loop. `setTimeout` schedules a macrotask,
 *  and unlike a resolved promise a macrotask lets the browser paint first --
 *  which is the entire point of this function. */
function yieldToEventLoop(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

/** Brute-force the ALTCHA proof-of-work: find the smallest n≥0 whose
 *  sha256hex(salt+n) equals the challenge, then base64-encode the solution the
 *  backend's verify() expects. Costs the honest client measurable CPU; the
 *  backend enforces the difficulty floor.
 *
 *  Two things here exist because the obvious implementation did not work.
 *
 *  It hashes synchronously rather than with `crypto.subtle.digest`. That API is
 *  promise-based, so grinding up to 200_000 candidates meant 200_000 promise
 *  round-trips. Desktop Chrome absorbs that at ~280k hashes/s and nobody
 *  notices; on iOS the per-call overhead dominates so completely that
 *  registration never finished at all -- minutes of a form that looked broken.
 *  See sha256.ts, which is checked against crypto.subtle in its own spec.
 *
 *  And the loop is time-sliced. `await` queues a microtask, and microtasks are
 *  ALL drained before the browser paints, so an unbroken run starves rendering:
 *  the submit button's spinner was set but never appeared. A macrotask every
 *  50ms is what lets a frame through.
 *
 *  `onProgress` receives how many candidates have been tried, so a caller can
 *  show real progress rather than an unmoving spinner.
 */
export async function solveAltcha(
  c: AltchaChallenge,
  onProgress?: (tried: number, total: number) => void,
): Promise<string> {
  const total = c.maxnumber + 1;
  let lastYield = Date.now();

  for (let n = 0; n <= c.maxnumber; n++) {
    if (sha256Hex(c.salt + n) === c.challenge) {
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

    if ((n + 1) % CHECK_EVERY === 0) {
      onProgress?.(n + 1, total);
      if (Date.now() - lastYield >= SLICE_MS) {
        await yieldToEventLoop();
        lastYield = Date.now();
      }
    }
  }

  throw new Error('ALTCHA challenge could not be solved');
}
