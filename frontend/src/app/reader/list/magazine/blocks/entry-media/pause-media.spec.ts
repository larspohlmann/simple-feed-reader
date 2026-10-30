import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { pauseMedia, pauseMediaWhileAnEntryIsOpen } from './pause-media';

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
    gif.toggleAttribute('data-animated', true);
    root.append(video, gif);

    pauseMedia(root);

    expect(pause).toHaveBeenCalledTimes(1);
    expect([video.dataset['paused'], gif.dataset['paused']]).toEqual(['yes', undefined]);
  });
});

describe('pauseMediaWhileAnEntryIsOpen', () => {
  afterEach(() => jest.restoreAllMocks());

  it('suspends autoplay and pauses media while an entry is open, and resumes after', () => {
    const pause = jest.spyOn(HTMLMediaElement.prototype, 'pause').mockReturnValue(undefined);
    const root = document.createElement('div');
    root.append(document.createElement('video'));
    const autoplay = { suspend: jest.fn(), resume: jest.fn() };
    const openEntryId = signal<number | null>(7);
    TestBed.runInInjectionContext(() => pauseMediaWhileAnEntryIsOpen(openEntryId, autoplay, root));

    TestBed.tick();
    expect(autoplay.suspend).toHaveBeenCalledTimes(1);
    expect(pause).toHaveBeenCalledTimes(1);

    openEntryId.set(null);
    TestBed.tick();
    expect(autoplay.resume).toHaveBeenCalledTimes(1);
  });
});
