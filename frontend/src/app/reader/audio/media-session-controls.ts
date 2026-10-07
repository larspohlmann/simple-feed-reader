import type { AudioTrack } from '../audio-player.service';

export interface MediaSessionActions {
  play(): void;
  pause(): void;
  skip(seconds: number): void;
  seek(seconds: number): void;
}

/** The OS media controls (lock screen, headphones, media keys) for the reader's player. */
export class MediaSessionControls {
  private readonly session = 'mediaSession' in navigator ? navigator.mediaSession : null;

  bind(actions: MediaSessionActions, skipSeconds: number): void {
    const session = this.session;
    if (!session) return;
    session.setActionHandler('play', () => actions.play());
    session.setActionHandler('pause', () => actions.pause());
    session.setActionHandler('seekbackward', () => actions.skip(-skipSeconds));
    session.setActionHandler('seekforward', () => actions.skip(skipSeconds));
    session.setActionHandler('seekto', (details) => {
      if (details.seekTime != null) actions.seek(details.seekTime);
    });
  }

  /** iOS swaps the lock screen's ±15 s buttons for track buttons once a track handler exists,
   *  so pass null while there is no track to step to. */
  setTrackSteps(previous: (() => void) | null, next: (() => void) | null): void {
    this.session?.setActionHandler('previoustrack', previous);
    this.session?.setActionHandler('nexttrack', next);
  }

  showTrack(track: AudioTrack | null): void {
    if (!this.session) return;
    if (!track || !('MediaMetadata' in window)) {
      this.session.metadata = null;
      return;
    }
    const artwork = track.imageUrl ? [{ src: track.imageUrl }] : [];
    this.session.metadata = new MediaMetadata({ title: track.title, artwork });
  }

  showPlaying(playing: boolean): void {
    if (this.session) this.session.playbackState = playing ? 'playing' : 'paused';
  }

  showPosition(position: number, duration: number): void {
    if (!this.session?.setPositionState || duration <= 0) return;
    this.session.setPositionState({ duration, position: Math.min(position, duration) });
  }
}
