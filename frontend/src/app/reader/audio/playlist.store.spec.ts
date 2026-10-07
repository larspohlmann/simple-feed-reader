import { PlaylistStore } from './playlist.store';
import type { AudioTrack } from '../audio-player.service';

const track: AudioTrack = {
  url: 'https://x.test/1.mp3',
  title: 'One',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: null,
};

describe('PlaylistStore', () => {
  const store = new PlaylistStore();
  beforeEach(() => localStorage.clear());

  it('round-trips the playlist and position', () => {
    store.save({ playlist: { tracks: [track], index: 0 }, position: 12 });

    expect(store.load()).toEqual({ playlist: { tracks: [track], index: 0 }, position: 12 });
  });

  it('clamps a stored index that outruns the tracks', () => {
    localStorage.setItem('sfr.audio', JSON.stringify({ tracks: [track], index: 4, position: 1 }));

    expect(store.load()?.playlist.index).toBe(0);
  });

  it.each(['not json', '{}', '{"tracks":[]}'])('forgets the unreadable %s', (raw) => {
    localStorage.setItem('sfr.audio', raw);

    expect(store.load()).toBeNull();
    expect(localStorage.getItem('sfr.audio')).toBeNull();
  });
});
