import type { AudioTrack } from '../app/reader/audio-player.service';
import type { ListenLauncher } from '../app/reader/audio/listen-launcher';
import type { StubAudioPlayer } from './stub-audio-player';

/** Listen with no saved place: the track starts on the stub player at once. */
export class StubListenLauncher implements Pick<ListenLauncher, 'listen'> {
  constructor(private readonly player: StubAudioPlayer) {}

  listen(track: AudioTrack, onStarted: () => void = () => undefined): void {
    this.player.play(track);
    onStarted();
  }

  asService(): ListenLauncher {
    return this as unknown as ListenLauncher;
  }
}
