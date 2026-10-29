import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  NgZone,
  computed,
  inject,
  output,
  signal,
} from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { carriesUrl, droppedUrl } from './dropped-url';

/** A link dragged in from another tab or window shows a full-window veil;
 *  dropping it hands the URL on. Drags that start in this page are left alone.
 *  The listeners run outside the zone: dragover fires every few milliseconds,
 *  and enter/leave at every element crossed. */
@Component({
  selector: 'app-url-drop-zone',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './url-drop-zone.component.html',
  styleUrl: './url-drop-zone.component.scss',
  host: { '[class.shown]': 'active()' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UrlDropZoneComponent {
  readonly urlDropped = output<string>();
  private readonly dialog = inject(Dialog);
  private readonly zone = inject(NgZone);
  private dragFromPage = false;
  /** dragenter/dragleave fire for every element crossed, so only the outermost
   *  pair means the drag entered or left the window. */
  private readonly depth = signal(0);
  protected readonly active = computed(() => this.depth() > 0);

  constructor() {
    const listeners: Record<string, (event: DragEvent) => void> = {
      dragstart: () => (this.dragFromPage = true),
      dragend: () => (this.dragFromPage = false),
      dragenter: (event) => this.onDragEnter(event),
      dragover: (event) => this.onDragOver(event),
      dragleave: () => this.onDragLeave(),
      drop: (event) => this.onDrop(event),
    };
    const document = inject(DOCUMENT);
    this.zone.runOutsideAngular(() => {
      for (const [type, listener] of Object.entries(listeners)) {
        document.addEventListener(type, listener as EventListener);
      }
    });
    inject(DestroyRef).onDestroy(() => {
      for (const [type, listener] of Object.entries(listeners)) {
        document.removeEventListener(type, listener as EventListener);
      }
    });
  }

  private onDragEnter(event: DragEvent): void {
    if (this.accepts(event)) this.depth.update((depth) => depth + 1);
  }

  private onDragOver(event: DragEvent): void {
    if (!this.active()) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
  }

  private onDragLeave(): void {
    if (this.active()) this.depth.update((depth) => depth - 1);
  }

  private onDrop(event: DragEvent): void {
    if (!this.active()) return;
    event.preventDefault();
    this.depth.set(0);
    const url = droppedUrl(event.dataTransfer);
    if (url) this.zone.run(() => this.urlDropped.emit(url));
  }

  private accepts(event: DragEvent): boolean {
    return (
      !this.dragFromPage && this.dialog.openDialogs.length === 0 && carriesUrl(event.dataTransfer)
    );
  }
}
