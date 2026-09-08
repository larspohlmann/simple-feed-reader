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
  const el = document.createElement('div');
  el.innerHTML = html;
  document.body.appendChild(el);
  return el;
}

const flush = (): Promise<void> => new Promise((r) => setTimeout(r, 0));

function firstPlay(el: HTMLElement): Promise<void> {
  el.querySelector('video')!.dispatchEvent(new Event('play'));
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
    const el = host('<video src="https://x.test/master.m3u8" poster="p.jpg"></video>');
    attachHlsStreams(el);
    await flush();

    expect(el.querySelector('video')!.getAttribute('src')).toBe('https://x.test/master.m3u8');
    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('attaches hls.js only on the first play, so preload="none" keeps its meaning', async () => {
    const el = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(el);
    await flush();
    expect(attachMedia).not.toHaveBeenCalled();

    await firstPlay(el);

    expect(loadSource).toHaveBeenCalledWith('https://x.test/master.m3u8');
    expect(attachMedia).toHaveBeenCalledWith(el.querySelector('video'));
  });

  it('takes the stream even when the browser claims native HLS, because Chrome claims and never plays', async () => {
    HTMLMediaElement.prototype.canPlayType = () => 'maybe';
    const el = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(el);
    await firstPlay(el);

    expect(attachMedia).toHaveBeenCalledWith(el.querySelector('video'));
  });

  it('leaves a file video alone', async () => {
    const el = host('<video src="https://x.test/a.mp4"></video>');
    attachHlsStreams(el);
    await firstPlay(el);

    expect(el.querySelector('video')!.getAttribute('src')).toBe('https://x.test/a.mp4');
    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('leaves the video alone when hls.js reports no support', async () => {
    supported = false;
    const el = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(el);
    await firstPlay(el);

    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('destroys the instance of a played video the re-render removed', async () => {
    const el = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(el);
    await firstPlay(el);
    el.innerHTML = '<p>re-rendered</p>';
    attachHlsStreams(el);
    await flush();

    expect(destroy).toHaveBeenCalledTimes(1);
  });

  it('does not arm the same still-connected video twice', async () => {
    const el = host('<video src="https://x.test/master.m3u8" poster="p.jpg"></video>');
    attachHlsStreams(el);
    await flush();
    attachHlsStreams(el);
    await firstPlay(el);

    expect(attachMedia).toHaveBeenCalledTimes(1);
  });
});
