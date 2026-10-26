import { frameWithWindow } from '../../../testing/frame-playback-testing';
import { pauseEmbedFrame } from './embed-frame-commands';

describe('pauseEmbedFrame', () => {
  it('pauses a YouTube player through the iframe API', () => {
    const { frame, posted } = frameWithWindow();

    pauseEmbedFrame(frame, 'youtube');

    expect(posted).toEqual([
      {
        message: { event: 'command', func: 'pauseVideo', args: [], id: 1, channel: 'widget' },
        origin: 'https://www.youtube-nocookie.com',
      },
    ]);
  });

  it('pauses a SoundCloud widget', () => {
    const { frame, posted } = frameWithWindow();

    pauseEmbedFrame(frame, 'soundcloud');

    expect(posted).toEqual([{ message: { method: 'pause' }, origin: 'https://w.soundcloud.com' }]);
  });
});
