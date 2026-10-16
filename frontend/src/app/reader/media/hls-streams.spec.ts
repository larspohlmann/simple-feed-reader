import { articleHost, attachedArticleHost } from '../../../testing/article-host-testing';
import {
  fakeHls,
  firstPlay,
  flushHls,
  pausePlaying,
  resetFakeHls,
  startPlaying,
} from '../../../testing/hls-testing';
import { armHlsStreams, releaseHlsStreams, streamsThroughHls } from './hls-streams';

jest.mock('hls.js', () => jest.requireActual('../../../testing/hls-testing').fakeHlsModule);

const PLAYLIST_VIDEO = '<video src="https://x.test/master.m3u8"></video>';
const videoIn = (host: HTMLElement): HTMLVideoElement => host.querySelector('video')!;

async function playedStream(): Promise<HTMLElement> {
  const host = attachedArticleHost(PLAYLIST_VIDEO);
  armHlsStreams(host);
  await firstPlay(videoIn(host));
  return host;
}

beforeEach(() => {
  resetFakeHls();
  document.body.innerHTML = '';
});

describe('armHlsStreams', () => {
  it('attaches nothing on render, so the idle poster keeps its playlist src and paints no spinner', async () => {
    const host = attachedArticleHost(
      '<video src="https://x.test/master.m3u8" poster="p.jpg"></video>',
    );
    armHlsStreams(host);
    await flushHls();

    expect(videoIn(host).getAttribute('src')).toBe('https://x.test/master.m3u8');
    expect(fakeHls.attachMedia).not.toHaveBeenCalled();
  });

  it('attaches hls.js only on the first play, so preload="none" keeps its meaning', async () => {
    const host = attachedArticleHost(PLAYLIST_VIDEO);
    armHlsStreams(host);
    await flushHls();
    expect(fakeHls.attachMedia).not.toHaveBeenCalled();

    await firstPlay(videoIn(host));

    expect(fakeHls.loadSource).toHaveBeenCalledWith('https://x.test/master.m3u8');
    expect(fakeHls.attachMedia).toHaveBeenCalledWith(videoIn(host));
  });

  it('resumes the video once hls.js attached, though the source swap paused it', async () => {
    const play = jest.fn(() => Promise.resolve());
    HTMLMediaElement.prototype.play = play;
    fakeHls.attachMedia.mockImplementationOnce((video: HTMLVideoElement) =>
      video.dispatchEvent(new Event('pause')),
    );
    await playedStream();

    expect(fakeHls.attachMedia).toHaveBeenCalledTimes(1);
    expect(play).toHaveBeenCalledTimes(1);
  });

  it('neither attaches nor resumes a video paused while hls.js was loading', async () => {
    const play = jest.fn(() => Promise.resolve());
    HTMLMediaElement.prototype.play = play;
    const host = attachedArticleHost(PLAYLIST_VIDEO);
    armHlsStreams(host);

    startPlaying(videoIn(host));
    pausePlaying(videoIn(host));
    await flushHls();

    expect(fakeHls.attachMedia).not.toHaveBeenCalled();
    expect(play).not.toHaveBeenCalled();
  });

  it('attaches a video paused and played again while hls.js was loading', async () => {
    const host = attachedArticleHost(PLAYLIST_VIDEO);
    armHlsStreams(host);

    startPlaying(videoIn(host));
    pausePlaying(videoIn(host));
    startPlaying(videoIn(host));
    await flushHls();

    expect(fakeHls.attachMedia).toHaveBeenCalledTimes(1);
  });

  it('starts the stream on the next play once a load was abandoned to a pause', async () => {
    const play = jest.fn(() => Promise.resolve());
    HTMLMediaElement.prototype.play = play;
    const host = attachedArticleHost(PLAYLIST_VIDEO);
    armHlsStreams(host);
    startPlaying(videoIn(host));
    pausePlaying(videoIn(host));
    await flushHls();

    await firstPlay(videoIn(host));

    expect(fakeHls.attachMedia).toHaveBeenCalledTimes(1);
    expect(play).toHaveBeenCalledTimes(1);
  });

  it('takes the stream even when the browser claims native HLS, because Chrome claims and never plays', async () => {
    HTMLMediaElement.prototype.canPlayType = () => 'maybe';
    await playedStream();

    expect(fakeHls.attachMedia).toHaveBeenCalled();
  });

  it('leaves a file video alone', async () => {
    const host = attachedArticleHost('<video src="https://x.test/a.mp4"></video>');
    armHlsStreams(host);
    await firstPlay(videoIn(host));

    expect(videoIn(host).getAttribute('src')).toBe('https://x.test/a.mp4');
    expect(fakeHls.attachMedia).not.toHaveBeenCalled();
  });

  it('leaves the video alone when hls.js reports no support', async () => {
    fakeHls.supported = false;
    await playedStream();

    expect(fakeHls.attachMedia).not.toHaveBeenCalled();
  });

  it('releases the stream of a played video the re-render removed', async () => {
    const host = await playedStream();
    host.innerHTML = '<p>re-rendered</p>';
    armHlsStreams(host);

    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);
  });

  it('keeps the streams of another host when re-rendering', async () => {
    const host = await playedStream();
    host.remove();
    armHlsStreams(attachedArticleHost('<p>another surface</p>'));

    expect(fakeHls.destroy).not.toHaveBeenCalled();
  });

  it('does not arm the same still-connected video twice', async () => {
    const host = attachedArticleHost(PLAYLIST_VIDEO);
    armHlsStreams(host);
    armHlsStreams(host);
    await firstPlay(videoIn(host));

    expect(fakeHls.attachMedia).toHaveBeenCalledTimes(1);
  });
});

describe('releaseHlsStreams', () => {
  it('destroys the stream its host played, once, though the swap detached its video', async () => {
    const host = await playedStream();
    host.innerHTML = '<p>swapped</p>';

    releaseHlsStreams(host);
    releaseHlsStreams(host);

    expect(fakeHls.destroy).toHaveBeenCalledTimes(1);
  });

  it('leaves the streams of another host playing', async () => {
    await playedStream();

    releaseHlsStreams(attachedArticleHost(PLAYLIST_VIDEO));

    expect(fakeHls.destroy).not.toHaveBeenCalled();
  });
});

describe('streamsThroughHls', () => {
  it('claims a playlist video before anything armed it', () => {
    expect(streamsThroughHls(videoIn(articleHost(PLAYLIST_VIDEO)))).toBe(true);
  });

  it('keeps claiming an armed video once hls.js swapped its source', () => {
    const host = articleHost(PLAYLIST_VIDEO);
    armHlsStreams(host);
    videoIn(host).src = 'blob:https://reader.test/stream';

    expect(streamsThroughHls(videoIn(host))).toBe(true);
  });

  it('leaves a file video to the browser', () => {
    expect(streamsThroughHls(videoIn(articleHost('<video src="https://x.test/a.mp4">')))).toBe(
      false,
    );
  });
});
