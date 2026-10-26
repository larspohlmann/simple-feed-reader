import { FramePlayback } from './frame-playback';
import { audioSlot, frameSlot, playbackSlotFactory, providerPlayback } from './playback-slots';
import { SoundCloudPlayback } from './soundcloud-playback';
import { YouTubePlayback } from './youtube-playback';

function fakeAudio(): HTMLAudioElement & { calls: string[] } {
  const calls: string[] = [];
  return {
    calls,
    pause: () => calls.push('pause'),
    removeAttribute: (name: string) => calls.push(`removeAttribute:${name}`),
    load: () => calls.push('load'),
  } as unknown as HTMLAudioElement & { calls: string[] };
}

function fakePlayback(): FramePlayback & { disposed: boolean } {
  const playback = { disposed: false, dispose: () => (playback.disposed = true) };
  return playback as unknown as FramePlayback & { disposed: boolean };
}

describe('audioSlot', () => {
  it('holds the element', () => {
    const element = fakeAudio();

    expect(audioSlot(element).element).toBe(element);
  });

  it('pauses, unsets the source and reloads on dispose', () => {
    const element = fakeAudio();

    audioSlot(element).dispose();

    expect(element.calls).toEqual(['pause', 'removeAttribute:src', 'load']);
  });
});

describe('frameSlot', () => {
  it('holds the playback as its element', () => {
    const playback = fakePlayback();

    expect(frameSlot(playback).element).toBe(playback);
  });

  it('disposes the playback on dispose', () => {
    const playback = fakePlayback();

    frameSlot(playback).dispose();

    expect(playback.disposed).toBe(true);
  });
});

describe('playbackSlotFactory', () => {
  it('builds a file slot from the audio factory', () => {
    const element = fakeAudio();
    const createSlot = playbackSlotFactory(
      () => element,
      () => fakePlayback(),
    );

    expect(createSlot('file').element).toBe(element);
  });

  it('builds a frame slot from the frame factory for the other kinds', () => {
    const playback = fakePlayback();
    const createSlot = playbackSlotFactory(fakeAudio, () => playback);

    expect(createSlot('youtube').element).toBe(playback);
    expect(createSlot('soundcloud').element).toBe(playback);
  });

  it('hands the frame factory the kind', () => {
    const asked: string[] = [];
    const createSlot = playbackSlotFactory(fakeAudio, (kind) => {
      asked.push(kind);
      return fakePlayback();
    });

    createSlot('soundcloud');

    expect(asked).toEqual(['soundcloud']);
  });
});

describe('providerPlayback', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('plays YouTube through the YouTube adapter', () => {
    const playback = providerPlayback('youtube');

    expect(playback).toBeInstanceOf(YouTubePlayback);
    playback.dispose();
  });

  it('plays SoundCloud through the SoundCloud adapter', () => {
    const playback = providerPlayback('soundcloud');

    expect(playback).toBeInstanceOf(SoundCloudPlayback);
    playback.dispose();
  });
});
