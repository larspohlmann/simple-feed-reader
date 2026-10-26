import { Injector, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AudioTrack } from '../audio-player.service';
import { AudioChapterPlayer } from './audio-chapter-player';
import { PlaybackPosition } from './chapter-player';

const EPISODE: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Ep',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 600,
};

function fakePlayer() {
  const current = signal<AudioTrack | null>(null);
  const position = signal(0);
  const duration = signal(0);
  return { current, position, duration, playFrom: jest.fn() };
}

describe('AudioChapterPlayer', () => {
  it('plays its episode from a chapter', () => {
    const player = fakePlayer();
    const chapters = new AudioChapterPlayer(player as never, EPISODE, TestBed.inject(Injector));

    chapters.playFrom(120);

    expect(player.playFrom).toHaveBeenCalledWith(EPISODE, 120);
  });

  it('follows the position only while its episode is the current track', () => {
    const player = fakePlayer();
    const chapters = new AudioChapterPlayer(player as never, EPISODE, TestBed.inject(Injector));
    const positions: (PlaybackPosition | null)[] = [];
    chapters.watch((position) => positions.push(position));

    TestBed.tick();
    player.current.set(EPISODE);
    player.position.set(130);
    player.duration.set(600);
    TestBed.tick();
    chapters.release();
    player.position.set(140);
    TestBed.tick();

    expect(positions).toEqual([null, { seconds: 130, durationSeconds: 600 }]);
  });

  it('knows no duration before the episode reports one', () => {
    const player = fakePlayer();
    const chapters = new AudioChapterPlayer(player as never, EPISODE, TestBed.inject(Injector));
    const positions: (PlaybackPosition | null)[] = [];
    chapters.watch((position) => positions.push(position));

    player.current.set(EPISODE);
    player.position.set(5);
    TestBed.tick();
    chapters.release();

    expect(positions).toEqual([{ seconds: 5, durationSeconds: null }]);
  });
});
