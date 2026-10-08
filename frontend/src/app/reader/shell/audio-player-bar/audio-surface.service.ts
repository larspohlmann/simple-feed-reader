import { Injectable, computed, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { accountSignal } from '../../../core/auth/session-identity';
import {
  BIG_PLAYER_TITLE_ID,
  BigPlayerComponent,
  BigPlayerData,
  BigPlayerExit,
} from './big-player/big-player.component';

type AudioSurfaceView = 'none' | 'playlist' | 'player';

/** Which of the player's two large surfaces is up, so opening one closes the other (#1442).
 *  Through the CDK overlay: the shell can carry a transform that would re-anchor a fixed child. */
@Injectable({ providedIn: 'root' })
export class AudioSurface {
  private readonly dialog = inject(Dialog);
  private readonly view = accountSignal<AudioSurfaceView>('none');
  private readonly sheetPlaylist = accountSignal(false);

  readonly playlistOpen = computed(() => this.view() === 'playlist');

  togglePlaylist(): void {
    this.view.set(this.playlistOpen() ? 'none' : 'playlist');
  }

  openPlayer(): void {
    if (this.view() === 'player') return;
    this.view.set('player');
    this.dialog
      .open<BigPlayerExit, BigPlayerData>(BigPlayerComponent, {
        panelClass: 'app-big-player',
        ariaLabelledBy: BIG_PLAYER_TITLE_ID,
        data: { playlistShown: this.sheetPlaylist },
      })
      .closed.subscribe((exit) => this.view.set(exit === 'playlist' ? 'playlist' : 'none'));
  }
}
