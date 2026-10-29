/** jsdom has no DataTransfer; drag specs build one from its payloads. */
export function fakeDataTransfer(payloads: Record<string, string>): DataTransfer {
  return {
    types: Object.keys(payloads),
    getData: (format: string) => payloads[format] ?? '',
    dropEffect: 'none',
  } as unknown as DataTransfer;
}
