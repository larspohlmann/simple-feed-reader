import type { FrameKind } from '../audio/playback/playback-kind';
import { methodCall, postToMixcloudFrame } from './mixcloud-widget-protocol';
import { postToWidgetFrame, widgetMessage } from './soundcloud-widget-protocol';
import { playerCommand, postToPlayerFrame } from './youtube-player-protocol';

/** Pauses a visible YouTube API player, SoundCloud widget or Mixcloud widget (#1815). */
export function pauseEmbedFrame(frame: HTMLIFrameElement, kind: FrameKind): void {
  switch (kind) {
    case 'youtube':
      postToPlayerFrame(frame, playerCommand('pauseVideo', []));
      return;
    case 'soundcloud':
      postToWidgetFrame(frame, widgetMessage('pause'));
      return;
    case 'mixcloud':
      postToMixcloudFrame(frame, methodCall('pause'));
      return;
  }
}
