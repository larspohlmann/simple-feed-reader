import type { AudioTrack, TrackDetails } from '../audio-player.service';
import type { ChapterDto } from '../models';
import {
  EMPTY_PLAYLIST,
  Playlist,
  append,
  currentTrack,
  withDetails,
  insertNext,
  move,
  remove,
  select,
} from './playlist';

function track(name: string): AudioTrack {
  return {
    url: `https://x.test/${name}.mp3`,
    title: name,
    faviconUrl: null,
    imageUrl: null,
    durationInSeconds: null,
  };
}

const [alpha, bravo, charlie, delta] = ['a', 'b', 'c', 'd'].map(track);

function titles(playlist: Playlist): string {
  return playlist.tracks.map((queued) => queued.title).join('');
}

function of(index: number, ...tracks: AudioTrack[]): Playlist {
  return { tracks, index };
}

describe('playlist', () => {
  describe('append', () => {
    it('makes the first track current', () => {
      const playlist = append(EMPTY_PLAYLIST, alpha);

      expect(titles(playlist)).toBe('a');
      expect(playlist.index).toBe(0);
    });

    it('adds at the end and keeps the current track', () => {
      const playlist = append(of(1, alpha, bravo), charlie);

      expect(titles(playlist)).toBe('abc');
      expect(playlist.index).toBe(1);
    });

    it('leaves a queued track where it is', () => {
      const playlist = of(0, alpha, bravo);

      expect(append(playlist, bravo)).toBe(playlist);
    });
  });

  describe('insertNext', () => {
    it('puts the track after the current one and makes it current', () => {
      const playlist = insertNext(of(0, alpha, bravo), charlie);

      expect(titles(playlist)).toBe('acb');
      expect(currentTrack(playlist)).toBe(charlie);
    });

    it('moves a queued later track up to play next', () => {
      const playlist = insertNext(of(0, alpha, bravo, charlie), charlie);

      expect(titles(playlist)).toBe('acb');
      expect(currentTrack(playlist)).toBe(charlie);
    });

    it('moves a played track down to play next', () => {
      const playlist = insertNext(of(1, alpha, bravo, charlie), alpha);

      expect(titles(playlist)).toBe('bac');
      expect(currentTrack(playlist)).toBe(alpha);
    });

    it('plays the queued track right after the current one where it is', () => {
      const playlist = insertNext(of(0, alpha, bravo, charlie), bravo);

      expect(titles(playlist)).toBe('abc');
      expect(currentTrack(playlist)).toBe(bravo);
    });

    it('leaves the current track where it is', () => {
      const playlist = of(0, alpha, bravo);

      expect(insertNext(playlist, alpha)).toBe(playlist);
    });

    it('starts an empty playlist', () => {
      const playlist = insertNext(EMPTY_PLAYLIST, alpha);

      expect(titles(playlist)).toBe('a');
      expect(playlist.index).toBe(0);
    });
  });

  describe('select', () => {
    it('makes the track at the index current', () => {
      expect(select(of(0, alpha, bravo), 1).index).toBe(1);
    });

    it.each([-1, 2])('ignores the out-of-range index %i', (index) => {
      const playlist = of(0, alpha, bravo);

      expect(select(playlist, index)).toBe(playlist);
    });
  });

  describe('move', () => {
    it('moves the current track and keeps it current', () => {
      const playlist = move(of(0, alpha, bravo, charlie), 0, 2);

      expect(titles(playlist)).toBe('bca');
      expect(currentTrack(playlist)).toBe(alpha);
    });

    it('keeps the current track current when another moves across it', () => {
      const down = move(of(1, alpha, bravo, charlie), 0, 2);
      const up = move(of(1, alpha, bravo, charlie), 2, 0);

      expect([titles(down), currentTrack(down)?.title]).toEqual(['bca', 'b']);
      expect([titles(up), currentTrack(up)?.title]).toEqual(['cab', 'b']);
    });

    it('clamps the target to the ends', () => {
      expect(titles(move(of(0, alpha, bravo, charlie), 0, 9))).toBe('bca');
      expect(titles(move(of(0, alpha, bravo, charlie), 2, -4))).toBe('cab');
    });

    it('ignores an unknown source or a move onto itself', () => {
      const playlist = of(0, alpha, bravo);

      expect(move(playlist, 5, 0)).toBe(playlist);
      expect(move(playlist, 1, 1)).toBe(playlist);
    });
  });

  describe('remove', () => {
    it('shifts the current index when an earlier track goes', () => {
      const playlist = remove(of(2, alpha, bravo, charlie), alpha.url);

      expect(titles(playlist)).toBe('bc');
      expect(currentTrack(playlist)).toBe(charlie);
    });

    it('keeps the index when a later track goes', () => {
      const playlist = remove(of(0, alpha, bravo, charlie), charlie.url);

      expect(currentTrack(playlist)).toBe(alpha);
    });

    it('hands the current place to the next track', () => {
      expect(currentTrack(remove(of(1, alpha, bravo, charlie, delta), bravo.url))).toBe(charlie);
    });

    it('falls back to the previous track when the last one was current', () => {
      expect(currentTrack(remove(of(2, alpha, bravo, charlie), charlie.url))).toBe(bravo);
    });

    it('empties when the only track goes', () => {
      expect(remove(of(0, alpha), alpha.url)).toBe(EMPTY_PLAYLIST);
    });

    it('ignores an unknown url', () => {
      const playlist = of(0, alpha);

      expect(remove(playlist, 'https://x.test/none.mp3')).toBe(playlist);
    });
  });

  describe('withDetails', () => {
    const chapters: ChapterDto[] = [{ startSeconds: 0, title: 'Intro' }];
    const nothing: TrackDetails = { title: null, durationSeconds: null, chapters: [] };

    it('gives the queued track its title, length and chapters and keeps the index', () => {
      const playlist = withDetails(of(1, alpha, bravo, charlie), bravo.url, {
        title: 'Bravo itself',
        durationSeconds: 300,
        chapters,
      });

      expect(playlist.tracks).toEqual([
        alpha,
        { ...bravo, title: 'Bravo itself', durationInSeconds: 300, chapters },
        charlie,
      ]);
      expect(playlist.index).toBe(1);
    });

    it('keeps the title when the details name none', () => {
      const playlist = withDetails(of(0, alpha), alpha.url, { ...nothing, durationSeconds: 300 });

      expect(playlist.tracks[0].title).toBe('a');
      expect(playlist.tracks[0].durationInSeconds).toBe(300);
    });

    it('keeps a length the track already has', () => {
      const timed = { ...alpha, durationInSeconds: 290 };

      const playlist = withDetails(of(0, timed), alpha.url, { ...nothing, durationSeconds: 300 });

      expect(playlist.tracks[0].durationInSeconds).toBe(290);
    });

    it('replaces the chapters a track already has', () => {
      const described = { ...alpha, chapters: [{ startSeconds: 5, title: 'Old' }] };

      const playlist = withDetails(of(0, described), alpha.url, { ...nothing, chapters });

      expect(playlist.tracks[0].chapters).toEqual(chapters);
    });

    it('keeps the chapters a track has when the details bring none', () => {
      const described = { ...alpha, chapters };

      const playlist = withDetails(of(0, described), alpha.url, { ...nothing, title: 'Renamed' });

      expect(playlist.tracks[0]).toEqual({ ...described, title: 'Renamed' });
    });

    it('adds no chapters key when neither has chapters', () => {
      const playlist = withDetails(of(0, alpha), alpha.url, { ...nothing, title: 'Renamed' });

      expect(playlist.tracks[0]).not.toHaveProperty('chapters');
    });

    it('ignores an unknown url', () => {
      const playlist = of(0, alpha);

      expect(withDetails(playlist, 'https://x.test/none.mp3', { ...nothing, chapters })).toBe(
        playlist,
      );
    });

    it('is the same playlist when the details add nothing', () => {
      const playlist = of(0, { ...alpha, durationInSeconds: 290, chapters });

      expect(withDetails(playlist, alpha.url, nothing)).toBe(playlist);
      expect(withDetails(playlist, alpha.url, { title: 'a', durationSeconds: 300, chapters })).toBe(
        playlist,
      );
    });
  });
});
