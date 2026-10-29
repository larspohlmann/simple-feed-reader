import { ChangeDetectionStrategy, Component, inject, output, signal } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { carriesUrl, droppedUrl } from './dropped-url';

/** A link dragged in from another tab or window shows a full-window veil;
 *  dropping it hands the URL on. Drags that start in this page are left alone. */
@Component({
  selector: 'app-url-drop-zone',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './url-drop-zone.component.html',
  styleUrl: './url-drop-zone.component.scss',
  host: {
    '[class.shown]': 'active()',
    '(document:dragstart)': 'dragFromPage = true',
    '(document:dragend)': 'dragFromPage = false',
    '(document:dragenter)': 'onDragEnter($event)',
    '(document:dragover)': 'onDragOver($event)',
    '(document:dragleave)': 'onDragLeave()',
    '(document:drop)': 'onDrop($event)',
  },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UrlDropZoneComponent {
  readonly urlDropped = output<string>();
  readonly active = signal(false);
  private readonly dialog = inject(Dialog);
  protected dragFromPage = false;
  /** dragenter/dragleave fire for every element crossed, so only the outermost
   *  pair means the drag entered or left the window. */
  private depth = 0;

  onDragEnter(event: DragEvent): void {
    if (!this.accepts(event)) return;
    this.depth++;
    this.active.set(true);
  }

  onDragOver(event: DragEvent): void {
    if (!this.active()) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
  }

  onDragLeave(): void {
    if (!this.active()) return;
    this.depth--;
    if (this.depth === 0) this.active.set(false);
  }

  onDrop(event: DragEvent): void {
    if (!this.active()) return;
    event.preventDefault();
    this.depth = 0;
    this.active.set(false);
    const url = event.dataTransfer ? droppedUrl(event.dataTransfer) : null;
    if (url) this.urlDropped.emit(url);
  }

  private accepts(event: DragEvent): boolean {
    return (
      !this.dragFromPage && this.dialog.openDialogs.length === 0 && carriesUrl(event.dataTransfer)
    );
  }
}
