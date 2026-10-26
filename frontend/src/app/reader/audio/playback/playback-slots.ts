import { FramePlayback } from './frame-playback';
import { hiddenPlaybackHost } from './hidden-frame';
import { PlaybackSlot } from './playback-element';
import { PlaybackKind } from './playback-kind';
import { SoundCloudPlayback } from './soundcloud-playback';
import { YouTubePlayback } from './youtube-playback';

export type FrameKind = Exclude<PlaybackKind, 'file'>;
export type PlaybackSlotFactory = (kind: PlaybackKind) => PlaybackSlot;
export type FramePlaybackFactory = (kind: FrameKind) => FramePlayback;

export function audioSlot(element: HTMLAudioElement): PlaybackSlot {
  return {
    element,
    dispose: () => {
      element.pause();
      element.removeAttribute('src');
      element.load();
    },
  };
}

export function frameSlot(playback: FramePlayback): PlaybackSlot {
  return { element: playback, dispose: () => playback.dispose() };
}

export function playbackSlotFactory(
  createAudio: () => HTMLAudioElement,
  createFrame: FramePlaybackFactory,
): PlaybackSlotFactory {
  return (kind) => (kind === 'file' ? audioSlot(createAudio()) : frameSlot(createFrame(kind)));
}

export function providerPlayback(kind: FrameKind): FramePlayback {
  if (kind === 'youtube') return new YouTubePlayback(hiddenPlaybackHost());
  return new SoundCloudPlayback(hiddenPlaybackHost());
}
