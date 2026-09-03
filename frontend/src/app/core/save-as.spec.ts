import { filenameFromContentDisposition, saveAs } from './save-as';

describe('filenameFromContentDisposition', () => {
  it('extracts a quoted filename', () => {
    expect(
      filenameFromContentDisposition(
        'attachment; filename="simplefeedreader-0_6_2-lars-pohlmann-at-googlemail-20260817.json.gz"',
        'fallback.json.gz',
      ),
    ).toBe('simplefeedreader-0_6_2-lars-pohlmann-at-googlemail-20260817.json.gz');
  });

  it('extracts an unquoted filename', () => {
    expect(
      filenameFromContentDisposition('attachment; filename=plain.json.gz', 'fallback.json.gz'),
    ).toBe('plain.json.gz');
  });

  it('falls back when the header is null', () => {
    expect(filenameFromContentDisposition(null, 'fallback.json.gz')).toBe('fallback.json.gz');
  });

  it('falls back when the header carries no filename', () => {
    expect(filenameFromContentDisposition('attachment', 'fallback.json.gz')).toBe(
      'fallback.json.gz',
    );
  });
});

describe('saveAs', () => {
  let createObjectURL: jest.Mock;
  let revokeObjectURL: jest.Mock;
  let clickSpy: jest.SpyInstance;
  let appendSpy: jest.SpyInstance;

  beforeEach(() => {
    jest.useFakeTimers();
    createObjectURL = jest.fn(() => 'blob:x');
    revokeObjectURL = jest.fn();
    (URL as unknown as { createObjectURL: unknown }).createObjectURL = createObjectURL;
    (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = revokeObjectURL;
    clickSpy = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    appendSpy = jest.spyOn(document.body, 'appendChild');
  });

  afterEach(() => {
    jest.useRealTimers();
    clickSpy.mockRestore();
    appendSpy.mockRestore();
  });

  it('clicks a hidden anchor carrying the object URL and the given filename', () => {
    const blob = new Blob(['x'], { type: 'text/x-opml' });

    saveAs(blob, 'feeds.opml');

    expect(createObjectURL).toHaveBeenCalledWith(blob);
    expect(clickSpy).toHaveBeenCalledTimes(1);
    const anchor = appendSpy.mock.calls[0][0] as HTMLAnchorElement;
    expect(anchor.href).toBe('blob:x');
    expect(anchor.download).toBe('feeds.opml');
    // Removed from the document again once the click has been dispatched.
    expect(document.body.contains(anchor)).toBe(false);
  });

  it('does not revoke the object URL synchronously', () => {
    saveAs(new Blob(['x']), 'feeds.opml');

    expect(revokeObjectURL).not.toHaveBeenCalled();
  });

  it('revokes the object URL once the next tick runs', () => {
    saveAs(new Blob(['x']), 'feeds.opml');

    jest.runAllTimers();

    expect(revokeObjectURL).toHaveBeenCalledWith('blob:x');
  });
});
