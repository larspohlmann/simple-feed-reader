import { pauseMedia } from './pause-media';

describe('pauseMedia', () => {
  afterEach(() => jest.restoreAllMocks());

  it('pauses the videos and leaves a looping GIF playing', () => {
    const pause = jest.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(function (
      this: HTMLMediaElement,
    ) {
      this.dataset['paused'] = 'yes';
    });
    const root = document.createElement('div');
    const video = document.createElement('video');
    const gif = document.createElement('video');
    gif.className = 'animation';
    root.append(video, gif);

    pauseMedia(root);

    expect(pause).toHaveBeenCalledTimes(1);
    expect([video.dataset['paused'], gif.dataset['paused']]).toEqual(['yes', undefined]);
  });
});
