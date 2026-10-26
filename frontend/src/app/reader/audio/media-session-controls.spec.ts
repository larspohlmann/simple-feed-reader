import type { AudioTrack } from '../audio-player.service';
import { FakeMediaSession, installFakeMediaSession } from '../../../testing/fake-media-session';
import { MediaSessionControls } from './media-session-controls';

const track: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Episode 1',
  faviconUrl: null,
  imageUrl: 'https://x.test/cover.jpg',
  durationInSeconds: 120,
  entry: { id: 7, title: 'Episode 1', feedTitle: 'The Feed', publishedAt: '', excerpt: '' },
};

describe('MediaSessionControls', () => {
  let session: FakeMediaSession;
  let restore: () => void;

  beforeEach(() => {
    ({ session, restore } = installFakeMediaSession());
  });

  afterEach(() => restore());

  it('tells the OS the playback speed, so its scrubber keeps pace', () => {
    new MediaSessionControls().showPosition(30, 120, 1.5);

    expect(session.setPositionState).toHaveBeenCalledWith({
      duration: 120,
      position: 30,
      playbackRate: 1.5,
    });
  });

  it('titles the metadata with the track, its feed as the artist, with its artwork', () => {
    new MediaSessionControls().showTrack(track, null);

    expect(session.metadata?.init).toEqual({
      title: 'Episode 1',
      artist: 'The Feed',
      artwork: [{ src: 'https://x.test/cover.jpg' }],
    });
  });

  it('titles the metadata with the chapter, the track as the artist and the feed as the album', () => {
    new MediaSessionControls().showTrack(track, { startSeconds: 60, title: 'Main' });

    expect(session.metadata?.init).toEqual({
      title: 'Main',
      artist: 'Episode 1',
      album: 'The Feed',
      artwork: [{ src: 'https://x.test/cover.jpg' }],
    });
  });

  it('names no feed and no artwork on a track that has neither', () => {
    new MediaSessionControls().showTrack({ ...track, imageUrl: null, entry: undefined }, null);

    expect(session.metadata?.init).toEqual({ title: 'Episode 1', artist: '', artwork: [] });
  });

  it('clears the metadata without a track', () => {
    const controls = new MediaSessionControls();
    controls.showTrack(track, null);

    controls.showTrack(null, null);

    expect(session.metadata).toBeNull();
  });
});
