/** Give up on a hung extraction and fall back (backend caps a fetch at ~20s;
 *  this is the client-side backstop for a stalled connection). */
export const READER_LOAD_TIMEOUT_MS = 30_000;
