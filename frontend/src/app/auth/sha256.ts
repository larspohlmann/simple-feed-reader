// A synchronous SHA-256, used only to grind the ALTCHA proof-of-work.
//
// `crypto.subtle.digest` is promise-based; 200_000 promise round-trips per
// grind stalls iOS for minutes (desktop absorbs it at ~280k hashes/s), so
// this hashes synchronously instead -- the caller time-slices the loop so
// the page still paints.
//
// Not a general-purpose crypto primitive: it hashes a public proof-of-work
// candidate, re-verified server-side. Checked against crypto.subtle in
// sha256.spec.ts.

const ROUND_CONSTANTS = new Uint32Array([
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

const HEX = Array.from({ length: 256 }, (_, index) => index.toString(16).padStart(2, '0'));

// Reused across calls: the grind runs this hundreds of thousands of times, and
// allocating a fresh message schedule each time is the one avoidable cost.
const MESSAGE_SCHEDULE = new Uint32Array(64);

/** SHA-256 of a UTF-8 string, lowercase hex. */
export function sha256Hex(input: string): string {
  const bytes = utf8Bytes(input);
  const bitLength = bytes.length * 8;

  // Pad: 0x80, then zeroes, then the 64-bit big-endian bit length.
  const padded = new Uint8Array(((bytes.length + 9 + 63) >> 6) << 6);
  padded.set(bytes);
  padded[bytes.length] = 0x80;
  // Lengths here are far below 2^32 bits, so the high word is always zero.
  padded[padded.length - 4] = (bitLength >>> 24) & 0xff;
  padded[padded.length - 3] = (bitLength >>> 16) & 0xff;
  padded[padded.length - 2] = (bitLength >>> 8) & 0xff;
  padded[padded.length - 1] = bitLength & 0xff;

  let h0 = 0x6a09e667,
    h1 = 0xbb67ae85,
    h2 = 0x3c6ef372,
    h3 = 0xa54ff53a,
    h4 = 0x510e527f,
    h5 = 0x9b05688c,
    h6 = 0x1f83d9ab,
    h7 = 0x5be0cd19;

  for (let blockOffset = 0; blockOffset < padded.length; blockOffset += 64) {
    for (let index = 0; index < 16; index++) {
      const byteOffset = blockOffset + index * 4;
      MESSAGE_SCHEDULE[index] =
        (padded[byteOffset] << 24) |
        (padded[byteOffset + 1] << 16) |
        (padded[byteOffset + 2] << 8) |
        padded[byteOffset + 3];
    }
    for (let index = 16; index < 64; index++) {
      const w15 = MESSAGE_SCHEDULE[index - 15];
      const w2 = MESSAGE_SCHEDULE[index - 2];
      const s0 = ((w15 >>> 7) | (w15 << 25)) ^ ((w15 >>> 18) | (w15 << 14)) ^ (w15 >>> 3);
      const s1 = ((w2 >>> 17) | (w2 << 15)) ^ ((w2 >>> 19) | (w2 << 13)) ^ (w2 >>> 10);
      MESSAGE_SCHEDULE[index] =
        (MESSAGE_SCHEDULE[index - 16] + s0 + MESSAGE_SCHEDULE[index - 7] + s1) | 0;
    }

    let workingA = h0,
      workingB = h1,
      workingC = h2,
      workingD = h3,
      workingE = h4,
      workingF = h5,
      workingG = h6,
      workingH = h7;

    for (let index = 0; index < 64; index++) {
      const S1 =
        ((workingE >>> 6) | (workingE << 26)) ^
        ((workingE >>> 11) | (workingE << 21)) ^
        ((workingE >>> 25) | (workingE << 7));
      const ch = (workingE & workingF) ^ (~workingE & workingG);
      const t1 = (workingH + S1 + ch + ROUND_CONSTANTS[index] + MESSAGE_SCHEDULE[index]) | 0;
      const S0 =
        ((workingA >>> 2) | (workingA << 30)) ^
        ((workingA >>> 13) | (workingA << 19)) ^
        ((workingA >>> 22) | (workingA << 10));
      const maj = (workingA & workingB) ^ (workingA & workingC) ^ (workingB & workingC);
      const t2 = (S0 + maj) | 0;
      workingH = workingG;
      workingG = workingF;
      workingF = workingE;
      workingE = (workingD + t1) | 0;
      workingD = workingC;
      workingC = workingB;
      workingB = workingA;
      workingA = (t1 + t2) | 0;
    }

    h0 = (h0 + workingA) | 0;
    h1 = (h1 + workingB) | 0;
    h2 = (h2 + workingC) | 0;
    h3 = (h3 + workingD) | 0;
    h4 = (h4 + workingE) | 0;
    h5 = (h5 + workingF) | 0;
    h6 = (h6 + workingG) | 0;
    h7 = (h7 + workingH) | 0;
  }

  return word(h0) + word(h1) + word(h2) + word(h3) + word(h4) + word(h5) + word(h6) + word(h7);
}

function word(x: number): string {
  return HEX[(x >>> 24) & 0xff] + HEX[(x >>> 16) & 0xff] + HEX[(x >>> 8) & 0xff] + HEX[x & 0xff];
}

/** UTF-8 encode. TextEncoder allocates a fresh Uint8Array per call, which is
 *  fine here -- the salts and numbers involved are a few dozen bytes. */
function utf8Bytes(text: string): Uint8Array {
  return new TextEncoder().encode(text);
}
