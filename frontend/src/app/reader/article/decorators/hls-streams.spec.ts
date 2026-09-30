import { attachHlsStreams } from './hls-streams';

const loadSource = jest.fn();
const attachMedia = jest.fn();
const destroy = jest.fn();
let supported = true;

class FakeHls {
  static isSupported = (): boolean => supported;
  loadSource = loadSource;
  attachMedia = attachMedia;
  destroy = destroy;
}

jest.mock('hls.js', () => ({
  __esModule: true,
  default: FakeHls,
}));

function host(html: string): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  document.body.appendChild(element);
  return element;
}

const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

function firstPlay(element: HTMLElement): Promise<void> {
  element.querySelector('video')!.dispatchEvent(new Event('play'));
  return flush();
}

describe('attachHlsStreams', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    supported = true;
    document.body.innerHTML = '';
    HTMLMediaElement.prototype.canPlayType = () => '';
    HTMLMediaElement.prototype.load = () => undefined;
    HTMLMediaElement.prototype.play = () => Promise.resolve();
  });

  it('attaches nothing on render, so the idle poster keeps its playlist src and paints no spinner', async () => {
    const element = host('<video src="https://x.test/master.m3u8" poster="p.jpg"></video>');
    attachHlsStreams(element);
    await flush();

    expect(element.querySelector('video')!.getAttribute('src')).toBe('https://x.test/master.m3u8');
    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('attaches hls.js only on the first play, so preload="none" keeps its meaning', async () => {
    const element = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(element);
    await flush();
    expect(attachMedia).not.toHaveBeenCalled();

    await firstPlay(element);

    expect(loadSource).toHaveBeenCalledWith('https://x.test/master.m3u8');
    expect(attachMedia).toHaveBeenCalledWith(element.querySelector('video'));
  });

  it('takes the stream even when the browser claims native HLS, because Chrome claims and never plays', async () => {
    HTMLMediaElement.prototype.canPlayType = () => 'maybe';
    const element = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(element);
    await firstPlay(element);

    expect(attachMedia).toHaveBeenCalledWith(element.querySelector('video'));
  });

  it('leaves a file video alone', async () => {
    const element = host('<video src="https://x.test/a.mp4"></video>');
    attachHlsStreams(element);
    await firstPlay(element);

    expect(element.querySelector('video')!.getAttribute('src')).toBe('https://x.test/a.mp4');
    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('leaves the video alone when hls.js reports no support', async () => {
    supported = false;
    const element = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(element);
    await firstPlay(element);

    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('destroys the instance of a played video the re-render removed', async () => {
    const element = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(element);
    await firstPlay(element);
    element.innerHTML = '<p>re-rendered</p>';
    attachHlsStreams(element);
    await flush();

    expect(destroy).toHaveBeenCalledTimes(1);
  });

  it('does not arm the same still-connected video twice', async () => {
    const element = host('<video src="https://x.test/master.m3u8" poster="p.jpg"></video>');
    attachHlsStreams(element);
    await flush();
    attachHlsStreams(element);
    await firstPlay(element);

    expect(attachMedia).toHaveBeenCalledTimes(1);
  });
});
