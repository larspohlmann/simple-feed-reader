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
  }

  close(): void {
    this.selected.set(null);
  }
}
