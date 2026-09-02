import { attachHlsStreams } from './hls-streams';

const loadSource = jest.fn();
const attachMedia = jest.fn();
const startLoad = jest.fn();
const destroy = jest.fn();
let supported = true;

class FakeHls {
  static isSupported = (): boolean => supported;
  loadSource = loadSource;
  attachMedia = attachMedia;
  startLoad = startLoad;
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

const flush = () => new Promise((r) => setTimeout(r, 0));

describe('attachHlsStreams', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    supported = true;
    document.body.innerHTML = '';
    HTMLMediaElement.prototype.canPlayType = () => '';
  });

  it('attaches hls.js to an m3u8 video when the browser has no native HLS', async () => {
    const el = host('<video src="https://x.test/master.m3u8" poster="p.jpg"></video>');
    attachHlsStreams(el);
    await flush();

    expect(loadSource).toHaveBeenCalledWith('https://x.test/master.m3u8');
    expect(attachMedia).toHaveBeenCalledWith(el.querySelector('video'));
  });

  it('starts loading only on the first play, so preload="none" keeps its meaning', async () => {
    const el = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(el);
    await flush();
    expect(startLoad).not.toHaveBeenCalled();

    el.querySelector('video')!.dispatchEvent(new Event('play'));
    expect(startLoad).toHaveBeenCalledTimes(1);
  });

  it('leaves a video alone when the browser plays HLS natively', async () => {
    HTMLMediaElement.prototype.canPlayType = () => 'probably';
    attachHlsStreams(host('<video src="https://x.test/master.m3u8"></video>'));
    await flush();

    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('leaves a file video alone', async () => {
    attachHlsStreams(host('<video src="https://x.test/a.mp4"></video>'));
    await flush();

    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('leaves the video alone when hls.js reports no support', async () => {
    supported = false;
    attachHlsStreams(host('<video src="https://x.test/master.m3u8"></video>'));
    await flush();

    expect(attachMedia).not.toHaveBeenCalled();
  });

  it('destroys the instance of a video the re-render removed', async () => {
    const el = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(el);
    await flush();
    el.innerHTML = '<p>re-rendered</p>';
    attachHlsStreams(el);
    await flush();

    expect(destroy).toHaveBeenCalledTimes(1);
  });
});
