import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable } from 'rxjs';
import { AudioPlayerService, AudioTrack } from '../audio-player.service';
import { ListenPositions, SavedPosition } from './listen-positions';
import {
  ResumeChoice,
  ResumeData,
  ResumeDialogComponent,
} from './resume-dialog/resume-dialog.component';

/** Where every Listen starts a track: at once, or after asking whether to continue a long one (#1866). */
@Injectable({ providedIn: 'root' })
export class ListenLauncher {
  private readonly player = inject(AudioPlayerService);
  private readonly positions = inject(ListenPositions);
  private readonly dialog = inject(Dialog);

  listen(track: AudioTrack, onStarted: () => void = () => undefined): void {
    const saved = this.savedPositionOf(track);
    if (!saved) {
      this.player.play(track);
      return onStarted();
    }
    this.ask(track, saved).subscribe((choice) => {
      if (!choice) return;
      if (choice === 'continue') this.player.playFrom(track, saved.seconds);
      else this.player.play(track);
      onStarted();
    });
  }

  private savedPositionOf(track: AudioTrack): SavedPosition | null {
    if (this.player.current()?.url === track.url) return null;
    return this.positions.get(track.url);
  }

  private ask(track: AudioTrack, saved: SavedPosition): Observable<ResumeChoice | undefined> {
    const data: ResumeData = {
      title: track.title,
      seconds: saved.seconds,
      durationSeconds: saved.durationSeconds,
    };
    return this.dialog.open<ResumeChoice>(ResumeDialogComponent, {
      data,
      panelClass: 'app-dialog',
    }).closed;
  }
}
