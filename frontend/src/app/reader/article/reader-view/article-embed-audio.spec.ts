import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { anEntry } from '../../../../testing/entry-testing';
import { frameWithWindow, messagesOf } from '../../../../testing/frame-playback-testing';
import { StubAudioPlayer } from '../../../../testing/stub-audio-player';
import { AudioTrack } from '../../audio-player.service';
import { EmbedMedia } from '../../audio/embed-media';
import { toEmbedTrack } from '../../audio/embed-track';
import { ChapterDto } from '../../models';
import type { TrackDetails } from '../../audio/audio-track';
import { EmbedListenControl, ListenActions } from '../decorators/reader-listen';
import { ArticleEmbedAudio, EmbedListenContext } from './article-embed-audio';

const VIDEO: EmbedMedia = {
  kind: 'youtube',
  url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
  posterUrl: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
};
const SOUND: EmbedMedia = {
  kind: 'soundcloud',
  url: 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F1',
  posterUrl: null,
};
const CHAPTERS: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
];
const VIDEO_DETAILS: TrackDetails = {
  title: 'Its own title',
  durationSeconds: 300,
  chapters: CHAPTERS,
  artworkUrl: null,
};
const ENTRY = anEntry({ id: 42, title: 'The video' });

function setUp(context: Partial<EmbedListenContext> = {}) {
  const player = new StubAudioPlayer();
  const embedAudio = new ArticleEmbedAudio(player.asService(), TestBed.inject(Injector));
  const listened: AudioTrack[] = [];
  const actions = embedAudio.actions({
    entry: ENTRY,
    detailsOf: (media) => (media.url === VIDEO.url ? VIDEO_DETAILS : null),
    onListened: (track) => listened.push(track),
    ...context,
  });
  return { player, embedAudio, listened, actions };
}

function control(media: EmbedMedia): EmbedListenControl & { shown: boolean[] } {
  const shown: boolean[] = [];
  return { media, shown, showQueued: (queued) => shown.push(queued) };
}

describe('ArticleEmbedAudio', () => {
  it('offers no actions without an entry to make a track of', () => {
    expect(setUp({ entry: null }).actions).toBeUndefined();
  });

  describe('listen', () => {
    it('pauses the visible player, then plays the embed with the details the body gives it', () => {
      const { player, listened, actions } = setUp();
      const { frame, posted } = frameWithWindow();

      (actions as ListenActions).listen(VIDEO, frame);

      const track = toEmbedTrack(ENTRY, VIDEO, VIDEO_DETAILS);
      expect(messagesOf(posted)).toEqual([
        { event: 'command', func: 'pauseVideo', args: [], id: 1, channel: 'widget' },
      ]);
      expect(player.played).toEqual([track]);
      expect(listened).toEqual([track]);
    });

    it('gives an embed the body knows nothing of a track named by the post', () => {
      const { player, actions } = setUp();

      (actions as ListenActions).listen(SOUND, document.createElement('iframe'));

      expect(player.played).toEqual([toEmbedTrack(ENTRY, SOUND)]);
    });
  });

  describe('toggleQueued', () => {
    it('queues an embed that is not in the playlist', () => {
      const { player, actions } = setUp();

      (actions as ListenActions).toggleQueued(VIDEO);

      expect(player.queue()).toEqual([VIDEO.url]);
    });

    it('takes a queued embed out of the playlist', () => {
      const { player, actions } = setUp();
      player.enqueue(toEmbedTrack(ENTRY, VIDEO));

      (actions as ListenActions).toggleQueued(VIDEO);

      expect(player.queue()).toEqual([]);
    });
  });

  describe('follow', () => {
    it('shows every control whether its embed is queued, and follows the playlist', () => {
      const { player, embedAudio } = setUp();
      const video = control(VIDEO);
      const sound = control(SOUND);
      player.enqueue(toEmbedTrack(ENTRY, SOUND));

      embedAudio.follow([video, sound]);
      TestBed.tick();
      player.enqueue(toEmbedTrack(ENTRY, VIDEO));
      player.dequeue(SOUND.url);
      TestBed.tick();

      expect(video.shown).toEqual([false, true]);
      expect(sound.shown).toEqual([true, false]);
    });

    it('lets go of the controls of an earlier body', () => {
      const { player, embedAudio } = setUp();
      const earlier = control(VIDEO);
      const later = control(VIDEO);
      embedAudio.follow([earlier]);
      TestBed.tick();

      embedAudio.follow([later]);
      TestBed.tick();
      player.enqueue(toEmbedTrack(ENTRY, VIDEO));
      TestBed.tick();

      expect(earlier.shown).toEqual([false]);
      expect(later.shown).toEqual([false, true]);
    });

    it('stops following once released', () => {
      const { player, embedAudio } = setUp();
      const video = control(VIDEO);
      embedAudio.follow([video]);
      TestBed.tick();

      embedAudio.release();
      player.enqueue(toEmbedTrack(ENTRY, VIDEO));
      TestBed.tick();

      expect(video.shown).toEqual([false]);
    });
  });
});
