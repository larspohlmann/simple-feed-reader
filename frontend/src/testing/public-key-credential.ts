/** jsdom has no `PublicKeyCredential`, so every spec starts from "unsupported"
 *  and installs only the statics it needs -- the Signal API spies among them. */
interface WindowWithCredential {
  PublicKeyCredential?: unknown;
}

export function stubPublicKeyCredential(statics: object): void {
  (window as unknown as WindowWithCredential).PublicKeyCredential = statics;
}

export function removePublicKeyCredential(): void {
  delete (window as unknown as WindowWithCredential).PublicKeyCredential;
}
