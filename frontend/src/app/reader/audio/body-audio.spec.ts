import { StubAudioPlayer } from '../../../testing/stub-audio-player';
import { StubListenLauncher } from '../../../testing/stub-listen-launcher';
import { anEntry } from '../../../testing/entry-testing';
import { embedTrackOrigin } from './embed-track';
import { BodyAudio, bodyAudioTrack } from './body-audio';

const ORIGIN = embedTrackOrigin(anEntry({ id: 7, title: 'The article' }));

function audioOf(html: string): HTMLAudioElement {
  const host = document.createElement('div');
  host.innerHTML = html;
  return host.querySelector('audio')!;
}

describe('bodyAudioTrack', () => {
  it('names and times the clip from its own attributes', () => {
    const track = bodyAudioTrack(
      audioOf(
        '<audio src="https://x.test/a.mp3" title="Ohne echte Lobby" data-duration="2871"></audio>',
      ),
      ORIGIN,
    );

    expect(track).toEqual(
      expect.objectContaining({
        url: 'https://x.test/a.mp3',
        title: 'Ohne echte Lobby',
        durationInSeconds: 2871,
        imageUrl: ORIGIN.imageUrl,
        faviconUrl: ORIGIN.faviconUrl,
        entry: ORIGIN.entry,
      }),
    );
  });

  it('falls back to the article title and an unknown length', () => {
    const track = bodyAudioTrack(
      audioOf('<audio src="https://x.test/a.mp3" data-duration="5:00"></audio>'),
      ORIGIN,
    );

    expect(track).toEqual(
      expect.objectContaining({ title: 'The article', durationInSeconds: null }),
    );
  });

  it('plays the first source of an older bare player', () => {
    const track = bodyAudioTrack(
      audioOf('<audio><source src="https://x.test/b.mp3" type="audio/mpeg"></audio>'),
      ORIGIN,
    );

    expect(track?.url).toBe('https://x.test/b.mp3');
  });

  it('has no track for a player with nothing to play', () => {
    expect(bodyAudioTrack(audioOf('<audio></audio>'), ORIGIN)).toBeNull();
  });
});

describe('BodyAudio', () => {
  function setUp() {
    const player = new StubAudioPlayer();
    const track = bodyAudioTrack(
      audioOf('<audio src="https://x.test/a.mp3" data-duration="2871"></audio>'),
      ORIGIN,
    )!;
    const offer = new BodyAudio(
      track,
      player.asService(),
      new StubListenLauncher(player).asService(),
    );
    return { player, track, offer };
  }

  it('shows the clip length', () => {
    expect(setUp().offer.duration()).toBe('47:51');
  });

  it('plays the clip through the launcher', () => {
    const { player, track, offer } = setUp();

    offer.play();

    expect(player.played).toEqual([track]);
  });

  it('queues and unqueues the clip', () => {
    const { offer } = setUp();

    offer.toggleQueued();
    expect(offer.queued()).toBe(true);

    offer.toggleQueued();
    expect(offer.queued()).toBe(false);
  });
});
