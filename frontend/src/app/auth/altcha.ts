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

/** Brute-forces the ALTCHA proof-of-work: finds the smallest n≥0 whose
 *  sha256hex(salt+n) equals the challenge, then base64-encodes the solution
 *  the backend's verify() expects.
 *
 *  Hashes synchronously rather than via `crypto.subtle.digest`: that API is
 *  promise-based, and 200_000 promise round-trips made registration hang for
 *  minutes on iOS, where per-call overhead dominates (desktop absorbs it at
 *  ~280k hashes/s). See sha256.ts, checked against crypto.subtle in its spec.
 *
 *  Time-sliced with a macrotask every `SLICE_MS`, not `await` alone: `await`
 *  queues a microtask, and microtasks all drain before paint, so an unbroken
 *  run starved rendering and the spinner never appeared.
 *
 *  `onProgress` reports candidates tried, so a caller can show real progress.
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
