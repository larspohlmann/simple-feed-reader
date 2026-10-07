import { StubAudioPlayer } from '../../../testing/stub-audio-player';
import { EntryDto } from '../models';
import { EntryAudio } from './entry-audio';

const EPISODE = 'https://x.test/ep.mp3';

const entry = (over: Partial<EntryDto> = {}): EntryDto =>
  ({
    title: 'Ep 1',
    faviconUrl: null,
    imageUrl: null,
    attachments: [{ url: EPISODE, mimeType: 'audio/mpeg' }],
    ...over,
  }) as EntryDto;

function setUp(subject: EntryDto | null = entry()) {
  const player = new StubAudioPlayer();
  const audio = new EntryAudio(() => subject, player.asService());
  return { player, audio };
}

describe('EntryAudio', () => {
  it('turns the audio enclosure into a track', () => {
    expect(setUp().audio.track()).toEqual(expect.objectContaining({ url: EPISODE, title: 'Ep 1' }));
  });

  it('has no track, and does nothing, for an entry without audio', () => {
    const { player, audio } = setUp(entry({ attachments: [] }));

    audio.play();
    audio.togglePlaying();
    audio.toggleQueued();

    expect(audio.track()).toBeNull();
    expect(audio.queued()).toBe(false);
    expect(player.played).toEqual([]);
    expect(player.queue()).toEqual([]);
  });

  it('has no track while no entry is open', () => {
    expect(setUp(null).audio.track()).toBeNull();
  });

  it('plays the track', () => {
    const { player, audio } = setUp();
    audio.play();
    expect(player.played).toEqual([audio.track()]);
  });

  it('reads as playing only while its own track is the one playing', () => {
    const { player, audio } = setUp();
    player.playing.set(true);
    player.current.set({ ...audio.track()!, url: 'https://x.test/other.mp3' });
    expect(audio.playing()).toBe(false);

    player.current.set(audio.track());
    expect(audio.playing()).toBe(true);

    player.playing.set(false);
    expect(audio.playing()).toBe(false);
  });

  it('pauses when its track is playing', () => {
    const { player, audio } = setUp();
    player.current.set(audio.track());
    player.playing.set(true);

    audio.togglePlaying();

    expect(player.toggles).toBe(1);
    expect(player.played).toEqual([]);
  });

  it('plays when another track is playing', () => {
    const { player, audio } = setUp();
    player.current.set({ ...audio.track()!, url: 'https://x.test/other.mp3' });
    player.playing.set(true);

    audio.togglePlaying();

    expect(player.played).toEqual([audio.track()]);
    expect(player.toggles).toBe(0);
  });

  it('adds the track to the playlist and, once queued, takes it out again', () => {
    const { player, audio } = setUp();

    audio.toggleQueued();
    expect(player.queue()).toEqual([EPISODE]);
    expect(audio.queued()).toBe(true);

    audio.toggleQueued();
    expect(player.queue()).toEqual([]);
    expect(audio.queued()).toBe(false);
  });
});
