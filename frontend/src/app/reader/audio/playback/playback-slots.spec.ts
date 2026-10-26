import { FakeAudio } from '../../../../testing/fake-audio-element';
import { FramePlayback } from './frame-playback';
import { playbackSlotFactory, providerPlayback } from './playback-slots';
import { SoundCloudPlayback } from './soundcloud-playback';
import { YouTubePlayback } from './youtube-playback';

function fakeAudio(): FakeAudio {
  return new FakeAudio();
}

function fakePlayback(): FramePlayback & { disposed: boolean } {
  const playback = { disposed: false, dispose: () => (playback.disposed = true) };
  return playback as unknown as FramePlayback & { disposed: boolean };
}

function slotsOf(audio: FakeAudio, playback: FramePlayback) {
  return playbackSlotFactory(
    () => audio as unknown as HTMLAudioElement,
    () => playback,
  );
}

describe('playbackSlotFactory', () => {
  it('builds a file slot holding the audio element', () => {
    const audio = fakeAudio();
    const createSlot = slotsOf(audio, fakePlayback());

    expect(createSlot('file').element).toBe(audio);
  });

  it('lets go of a file slot by pausing, unsetting the source and reloading the element', () => {
    const audio = fakeAudio();
    audio.src = 'https://x.test/ep.mp3';
    const createSlot = slotsOf(audio, fakePlayback());

    createSlot('file').dispose();

    expect(audio.pause).toHaveBeenCalled();
    expect(audio.src).toBe('');
    expect(audio.load).toHaveBeenCalled();
  });

  it('builds a frame slot from the frame factory for the other kinds', () => {
    const playback = fakePlayback();
    const createSlot = slotsOf(fakeAudio(), playback);

    expect(createSlot('youtube').element).toBe(playback);
    expect(createSlot('soundcloud').element).toBe(playback);
  });

  it('hands the frame factory the kind', () => {
    const asked: string[] = [];
    const createSlot = playbackSlotFactory(
      () => fakeAudio() as unknown as HTMLAudioElement,
      (kind) => {
        asked.push(kind);
        return fakePlayback();
      },
    );

    createSlot('soundcloud');

    expect(asked).toEqual(['soundcloud']);
  });

  it('lets go of a frame slot by disposing the playback', () => {
    const playback = fakePlayback();
    const createSlot = slotsOf(fakeAudio(), playback);

    createSlot('youtube').dispose();

    expect(playback.disposed).toBe(true);
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
