import { signal } from '@angular/core';
import { PlaybackPosition } from '../chapters/chapter-player';
import type { ChapterDto } from '../models';
import type { AudioTrack } from './audio-track';
import { TrackChapters, nextChapterStart, previousChapterStart } from './track-chapters';

const starts = [30, 90, 180];
const threshold = 3;
const track: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Episode',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: null,
};

describe('previousChapterStart', () => {
  it('restarts the current chapter once past the threshold', () => {
    expect(previousChapterStart(starts, 94, threshold)).toBe(90);
  });

  it('steps back to the chapter before while still within the threshold', () => {
    expect(previousChapterStart(starts, 92, threshold)).toBe(30);
  });

  it('steps back from exactly the threshold, which is not yet past it', () => {
    expect(previousChapterStart(starts, 93, threshold)).toBe(30);
  });

  it('has nowhere to go inside the first chapter', () => {
    expect(previousChapterStart(starts, 31, threshold)).toBeNull();
  });

  it('restarts the first chapter once past the threshold', () => {
    expect(previousChapterStart(starts, 60, threshold)).toBe(30);
  });

  it('has nowhere to go before the first chapter', () => {
    expect(previousChapterStart(starts, 10, threshold)).toBeNull();
  });

  it('has nowhere to go without chapters', () => {
    expect(previousChapterStart([], 42, threshold)).toBeNull();
  });
});

describe('nextChapterStart', () => {
  it('goes to the following chapter', () => {
    expect(nextChapterStart(starts, 94)).toBe(180);
  });

  it('goes to the first chapter from before it', () => {
    expect(nextChapterStart(starts, 10)).toBe(30);
  });

  it('has nowhere to go in the last chapter', () => {
    expect(nextChapterStart(starts, 500)).toBeNull();
  });

  it('has nowhere to go without chapters', () => {
    expect(nextChapterStart([], 42)).toBeNull();
  });
});

describe('TrackChapters', () => {
  const chapters: ChapterDto[] = starts.map((startSeconds) => ({
    startSeconds,
    title: `From ${startSeconds}`,
  }));

  function setUp(initial: ChapterDto[] = chapters) {
    const position = signal<PlaybackPosition>({ seconds: 0, durationSeconds: null });
    const player = { current: jest.fn(() => track), seek: jest.fn(), playFrom: jest.fn() };
    const followed = new TrackChapters(signal(initial), position, player);
    return { position, followed, player };
  }

  it('follows the chapter at the position', () => {
    const { position, followed } = setUp();

    position.set({ seconds: 100, durationSeconds: null });

    expect(followed.index()).toBe(1);
    expect(followed.current()?.title).toBe('From 90');
    expect(followed.progress()).toEqual({ fraction: 1 / 9, secondsLeft: 80 });
    expect(followed.hasPrevious()).toBe(true);
    expect(followed.hasNext()).toBe(true);
  });

  it('knows no chapter before the first one', () => {
    const { position, followed } = setUp();

    position.set({ seconds: 10, durationSeconds: 600 });

    expect(followed.index()).toBe(-1);
    expect(followed.current()).toBeNull();
    expect(followed.progress()).toBeNull();
    expect(followed.hasPrevious()).toBe(false);
    expect(followed.nextStart()).toBe(30);
  });

  it('measures the last chapter only once the duration is known', () => {
    const { position, followed } = setUp();
    position.set({ seconds: 240, durationSeconds: null });
    expect(followed.progress()).toBeNull();

    position.set({ seconds: 240, durationSeconds: 300 });

    expect(followed.progress()).toEqual({ fraction: 0.5, secondsLeft: 60 });
    expect(followed.hasNext()).toBe(false);
  });

  it('has nothing to follow on a track without chapters', () => {
    const { position, followed } = setUp([]);

    position.set({ seconds: 100, durationSeconds: 300 });

    expect(followed.index()).toBe(-1);
    expect(followed.current()).toBeNull();
    expect(followed.progress()).toBeNull();
    expect(followed.previousStart()).toBeNull();
    expect(followed.nextStart()).toBeNull();
  });

  it('seeks to the previous chapter start', () => {
    const { position, followed, player } = setUp();
    position.set({ seconds: 92, durationSeconds: null });

    followed.previous();

    expect(player.seek).toHaveBeenCalledWith(30);
  });

  it('seeks to the next chapter start', () => {
    const { position, followed, player } = setUp();
    position.set({ seconds: 100, durationSeconds: null });

    followed.next();

    expect(player.seek).toHaveBeenCalledWith(180);
  });

  it('does not seek with nowhere to go', () => {
    const { position, followed, player } = setUp();
    position.set({ seconds: 500, durationSeconds: null });

    followed.next();

    expect(player.seek).not.toHaveBeenCalled();
  });

  it('plays the track from a chapter start', () => {
    const { followed, player } = setUp();

    followed.play(1);

    expect(player.playFrom).toHaveBeenCalledWith(track, 90);
  });

  it('plays nothing for a chapter that does not exist, or without a track', () => {
    const { followed, player } = setUp();

    followed.play(9);
    player.current.mockReturnValue(null as never);
    followed.play(1);

    expect(player.playFrom).not.toHaveBeenCalled();
  });
});
