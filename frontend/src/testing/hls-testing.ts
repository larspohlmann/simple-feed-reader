/** The hls.js stand-in a spec installs with
 *  `jest.mock('hls.js', () => jest.requireActual('…/testing/hls-testing').fakeHlsModule)`. */
export const fakeHls = {
  supported: true,
  loadSource: jest.fn(),
  attachMedia: jest.fn(),
  destroy: jest.fn(),
};

class FakeHls {
  static isSupported = (): boolean => fakeHls.supported;
  loadSource = fakeHls.loadSource;
  attachMedia = fakeHls.attachMedia;
  destroy = fakeHls.destroy;
}

export const fakeHlsModule = { __esModule: true, default: FakeHls };

/** Resets the fake and gives jsdom's media elements the playback methods it lacks. */
export function resetFakeHls(): void {
  jest.clearAllMocks();
  fakeHls.supported = true;
  HTMLMediaElement.prototype.canPlayType = () => '';
  HTMLMediaElement.prototype.load = () => undefined;
  HTMLMediaElement.prototype.play = () => Promise.resolve();
}

/** Waits for hls.js's lazy import to settle. */
export const flushHls = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

/** jsdom keeps `paused` at true whatever happens, so these set it the way a browser would. */
export function startPlaying(video: HTMLVideoElement): void {
  Object.defineProperty(video, 'paused', { value: false, configurable: true });
  video.dispatchEvent(new Event('play'));
}

export function pausePlaying(video: HTMLVideoElement): void {
  Object.defineProperty(video, 'paused', { value: true, configurable: true });
  video.dispatchEvent(new Event('pause'));
}

export function firstPlay(video: HTMLVideoElement): Promise<void> {
  startPlaying(video);
  return flushHls();
}
