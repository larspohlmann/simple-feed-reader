import { ReleasableVideo } from './releasable-video';

const SOURCE = 'https://video.test/clip.mp4';

let load: jest.SpyInstance;
let pause: jest.SpyInstance;

beforeEach(() => {
  load = jest.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => undefined);
  pause = jest.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => undefined);
});

afterEach(() => jest.restoreAllMocks());

function at(video: HTMLVideoElement, seconds: number): void {
  Object.defineProperty(video, 'currentTime', {
    value: seconds,
    writable: true,
    configurable: true,
  });
}

describe('ReleasableVideo', () => {
  it('carries no source until it is attached', () => {
    const video = new ReleasableVideo(document.createElement('video'), SOURCE);

    expect(video.element.hasAttribute('src')).toBe(false);
    video.attach();
    expect(video.element.getAttribute('src')).toBe(SOURCE);
  });

  it('frees its media on release but keeps the element', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();

    video.release();

    expect(video.element).toBe(element);
    expect(element.hasAttribute('src')).toBe(false);
    expect(pause).toHaveBeenCalled();
    expect(load).toHaveBeenCalledTimes(1);
  });

  it('does nothing when released without a source', () => {
    new ReleasableVideo(document.createElement('video'), SOURCE).release();

    expect(load).not.toHaveBeenCalled();
  });

  it('resumes where it was released, once the media is back', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();
    at(element, 42);
    video.release();
    at(element, 0);

    video.attach();
    element.dispatchEvent(new Event('loadedmetadata'));

    expect(element.currentTime).toBe(42);
  });

  it('starts over once a video played to its end', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();
    at(element, 30);
    Object.defineProperty(element, 'ended', { value: true, configurable: true });
    video.release();
    at(element, 0);

    video.attach();
    element.dispatchEvent(new Event('loadedmetadata'));

    expect(element.currentTime).toBe(0);
  });

  it('keeps its place when released again before the media returned', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();
    at(element, 42);
    video.release();
    at(element, 0);
    video.attach();
    video.release();

    video.attach();
    element.dispatchEvent(new Event('loadedmetadata'));

    expect(element.currentTime).toBe(42);
  });

  it('does not seek on a stray loadedmetadata after a pending resume was dropped', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();
    at(element, 42);
    video.release();
    at(element, 0);
    video.attach();
    video.release();

    element.dispatchEvent(new Event('loadedmetadata'));

    expect(element.currentTime).toBe(0);
  });

  it('seeks only once per attach', () => {
    const element = document.createElement('video');
    const video = new ReleasableVideo(element, SOURCE);
    video.attach();
    at(element, 42);
    video.release();
    video.attach();
    element.dispatchEvent(new Event('loadedmetadata'));
    at(element, 5);

    element.dispatchEvent(new Event('loadedmetadata'));

    expect(element.currentTime).toBe(5);
  });
});
