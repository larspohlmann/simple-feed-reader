import type { AudioTrack } from '../audio-player.service';
import { PlaylistPersistence } from './playlist-persistence';
import { PlaylistStore, SavedPlaylist } from './playlist.store';

const track: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Episode',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: null,
};

function setUp(snapshot: () => SavedPlaylist | null) {
  const store = new PlaylistStore();
  const persistence = new PlaylistPersistence(store, snapshot);
  return { store, persistence };
}

function at(position: number): SavedPlaylist {
  return { playlist: { tracks: [track], index: 0 }, position };
}

function savedPosition(): number | null {
  const raw = localStorage.getItem('sfr.audio');
  return raw ? JSON.parse(raw).position : null;
}

describe('PlaylistPersistence', () => {
  beforeEach(() => {
    localStorage.clear();
    jest.useFakeTimers();
  });

  afterEach(() => jest.useRealTimers());

  it('saves the snapshot', () => {
    setUp(() => at(30)).persistence.save();

    expect(savedPosition()).toBe(30);
  });

  it('clears the store when there is nothing to save', () => {
    const { persistence } = setUp(() => null);
    localStorage.setItem('sfr.audio', JSON.stringify(at(30)));

    persistence.save();

    expect(localStorage.getItem('sfr.audio')).toBeNull();
  });

  it('loads what the store holds', () => {
    const { persistence } = setUp(() => null);
    localStorage.setItem('sfr.audio', JSON.stringify({ tracks: [track], index: 0, position: 9 }));

    expect(persistence.load()).toEqual(at(9));
  });
});
