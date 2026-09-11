import { Component, computed, forwardRef, inject, input, output, signal } from '@angular/core';
import { CdkConnectedOverlay, ConnectedPosition } from '@angular/cdk/overlay';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../models';
import { LanguageService } from '../../core/language.service';
import { relativeTime } from '../format';
import { EntryRowComponent } from '../entry-row/entry-row.component';

let nextId = 0;

const OVERLAY_POSITIONS: ConnectedPosition[] = [
  { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 6 },
  { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -6 },
];

@Component({
  selector: 'app-entry-duplicates',
  // forwardRef: entry-row renders entry-duplicates for its own footer, so a
  // plain reference here would resolve EntryRowComponent mid-import-cycle and
  // read as undefined, depending on which of the two loads first.
  imports: [TranslocoPipe, CdkConnectedOverlay, forwardRef(() => EntryRowComponent)],
  templateUrl: './entry-duplicates.component.html',
  styleUrl: './entry-duplicates.component.scss',
})
export class EntryDuplicatesComponent {
  readonly entry = input.required<EntryDto>();
  readonly open = output<EntryDto>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();

  private readonly language = inject(LanguageService);
  readonly copies = computed(() => this.entry().duplicates ?? []);
  readonly when = (copy: EntryDto): string =>
    relativeTime(copy.publishedAt ?? copy.createdAt, this.language.lang());

  protected readonly selected = signal<EntryDto | null>(null);
  // The card shown in the popover: a clone of `selected`, flipped locally on
  // each action so the icons react without the copy ever joining the list
  // the shell keeps in sync with the backend.
  protected readonly displayed = signal<EntryDto | null>(null);
  protected readonly origin = signal<HTMLElement | null>(null);
  protected readonly positions = OVERLAY_POSITIONS;
  protected readonly panelId = `dup-popover-${nextId++}`;

  toggle(copy: EntryDto, event: Event): void {
    event.stopPropagation();
    if (this.selected() === copy) {
      this.close();
      return;
    }
    this.origin.set(event.currentTarget as HTMLElement);
    this.selected.set(copy);
    this.displayed.set({ ...copy });
  }

  openCopy(copy: EntryDto): void {
    this.open.emit(copy);
    this.close();
  }

  // Emit before flipping the clone: the shell reads the flag's pre-toggle
  // value to decide which way to PATCH.
  favoriteCopy(copy: EntryDto): void {
    this.favorite.emit(copy);
    this.displayed.update((c) => (c ? { ...c, isFavorite: !c.isFavorite } : c));
  }

  keepCopy(copy: EntryDto): void {
    this.keep.emit(copy);
    this.displayed.update((c) => (c ? { ...c, isKept: !c.isKept } : c));
  }

  readCopy(copy: EntryDto): void {
    this.read.emit(copy);
    this.displayed.update((c) => (c ? { ...c, isViewed: !c.isViewed } : c));
  }

  close(): void {
    this.selected.set(null);
    this.displayed.set(null);
  }
}
