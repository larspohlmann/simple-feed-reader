import { Component, computed, forwardRef, inject, input, signal } from '@angular/core';
import { CdkConnectedOverlay, ConnectedPosition } from '@angular/cdk/overlay';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { LanguageService } from '../../../core/i18n/language.service';
import { relativeTime } from '../../format';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { IconComponent } from '../../../shared/icon/icon.component';

let nextId = 0;

const OVERLAY_POSITIONS: ConnectedPosition[] = [
  { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 6 },
  { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
  { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -6 },
  { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
];

@Component({
  selector: 'app-entry-duplicates',
  // forwardRef: entry-row renders entry-duplicates for its own footer, so a
  // plain reference here would resolve EntryRowComponent mid-import-cycle and
  // read as undefined, depending on which of the two loads first.
  imports: [TranslocoPipe, IconComponent, CdkConnectedOverlay, forwardRef(() => EntryRowComponent)],
  templateUrl: './entry-duplicates.component.html',
  styleUrl: './entry-duplicates.component.scss',
  providers: [
    { provide: EntryActionHandler, useExisting: forwardRef(() => EntryDuplicatesComponent) },
  ],
})
export class EntryDuplicatesComponent implements EntryActionHandler {
  readonly entry = input.required<EntryDto>();
  private readonly shellActions = inject(EntryActionHandler, { skipSelf: true });

  private readonly language = inject(LanguageService);
  readonly copies = computed(() =>
    (this.entry().duplicates ?? []).map((copy) => ({
      copy,
      when: relativeTime(copy.publishedAt ?? copy.createdAt, this.language.lang()),
    })),
  );

  // The card shown in the popover: a clone of the clicked copy, flipped locally
  // on each action so the icons react without the copy ever joining the list
  // the shell keeps in sync with the backend.
  protected readonly displayed = signal<EntryDto | null>(null);
  protected readonly origin = signal<HTMLElement | null>(null);
  protected readonly positions = OVERLAY_POSITIONS;
  protected readonly panelId = `dup-popover-${nextId++}`;

  toggle(copy: EntryDto, event: Event): void {
    event.stopPropagation();
    if (this.displayed()?.id === copy.id) {
      this.close();
      return;
    }
    this.origin.set(event.currentTarget as HTMLElement);
    this.displayed.set({ ...copy });
  }

  open(copy: EntryDto): void {
    this.shellActions.open(copy);
    this.close();
  }

  // Call before flipping the clone: the shell reads the flag's pre-toggle
  // value to decide which way to PATCH.
  favorite(copy: EntryDto): void {
    this.shellActions.favorite(copy);
    this.displayed.update((current) =>
      current ? { ...current, isFavorite: !current.isFavorite } : current,
    );
  }

  keep(copy: EntryDto): void {
    this.shellActions.keep(copy);
    this.displayed.update((current) =>
      current ? { ...current, isKept: !current.isKept } : current,
    );
  }

  toggleRead(copy: EntryDto): void {
    this.shellActions.toggleRead(copy);
    this.displayed.update((current) =>
      current ? { ...current, isViewed: !current.isViewed } : current,
    );
  }

  close(): void {
    this.displayed.set(null);
  }
}
