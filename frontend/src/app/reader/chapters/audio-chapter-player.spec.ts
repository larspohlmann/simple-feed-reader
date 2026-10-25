import { Injector, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AudioTrack } from '../audio-player.service';
import { AudioChapterPlayer } from './audio-chapter-player';

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
  return { current, position, playFrom: jest.fn() };
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
    const positions: (number | null)[] = [];
    chapters.watch((seconds) => positions.push(seconds));

    TestBed.tick();
    player.current.set(EPISODE);
    player.position.set(130);
    TestBed.tick();
    chapters.release();
    player.position.set(140);
    TestBed.tick();

    expect(positions).toEqual([null, 130]);
  });
});
