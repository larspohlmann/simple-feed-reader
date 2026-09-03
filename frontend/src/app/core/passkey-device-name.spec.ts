import { defaultPasskeyName } from './passkey-device-name';

const CHROME_MACOS =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

const SAFARI_MACOS =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 ' +
  '(KHTML, like Gecko) Version/17.0 Safari/605.1.15';

const FIREFOX_WINDOWS =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/119.0';

const EDGE_WINDOWS =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ' +
  'Chrome/119.0.0.0 Safari/537.36 Edg/119.0.0.0';

const SAFARI_IPHONE =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 ' +
  '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

const CHROME_ANDROID =
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) ' +
  'Chrome/119.0.0.0 Mobile Safari/537.36';

describe('defaultPasskeyName', () => {
  it('names Chrome on macOS', () => {
    expect(defaultPasskeyName(CHROME_MACOS)).toBe('Chrome on macOS');
  });

  it('names Safari on macOS, not "Chrome" -- Chrome UAs also carry a Safari token', () => {
    expect(defaultPasskeyName(SAFARI_MACOS)).toBe('Safari on macOS');
  });

  it('names Firefox on Windows', () => {
    expect(defaultPasskeyName(FIREFOX_WINDOWS)).toBe('Firefox on Windows');
  });

  it('names Edge, not "Chrome" -- Edge UAs also carry a Chrome token', () => {
    expect(defaultPasskeyName(EDGE_WINDOWS)).toBe('Edge on Windows');
  });

  it('names Safari on iPhone', () => {
    expect(defaultPasskeyName(SAFARI_IPHONE)).toBe('Safari on iPhone');
  });

  it('names Chrome on Android, not "Linux" -- Android UAs also carry a Linux token', () => {
    expect(defaultPasskeyName(CHROME_ANDROID)).toBe('Chrome on Android');
  });

  it('falls back to generic names for an unrecognised user agent', () => {
    expect(defaultPasskeyName('some-unusual-client/1.0')).toBe('Browser on this device');
  });
});
