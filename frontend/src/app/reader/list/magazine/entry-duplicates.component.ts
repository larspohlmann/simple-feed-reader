import { Component, computed, forwardRef, inject, input, signal } from '@angular/core';
import { CdkConnectedOverlay, ConnectedPosition } from '@angular/cdk/overlay';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { LanguageService } from '../../../core/i18n/language.service';
import { relativeTime } from '../../format';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SubscriptionRows } from '../../state/subscription-rows';

let nextId = 0;

const OVERLAY_POSITIONS: ConnectedPosition[] = [
  { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 6 },
  { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
  { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -6 },
  { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
];

/** The footer's chip limit: a capped footer keeps a card's height predictable for the planner (#2017). */
export const ALSO_FOOT_NAMES = 4;

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
  /** The row the footer sits on; deleting from a copy's card deletes this row's post (#1972). */
  readonly entry = input.required<EntryDto>();
  readonly copies = input.required<readonly EntryDto[]>();
  readonly label = input.required<string>();
  private readonly shellActions = inject(EntryActionHandler, { skipSelf: true });

  private readonly language = inject(LanguageService);
  private readonly rows = inject(SubscriptionRows);
  private readonly named = computed(() =>
    this.copies().map((copy) => ({
      copy,
      feed: this.rows.find(copy.subscriptionId)?.title ?? copy.source,
      when: relativeTime(copy.publishedAt ?? copy.createdAt, this.language.lang()),
    })),
  );
  private readonly expanded = signal(false);
  protected readonly shown = computed(() =>
    this.expanded() ? this.named() : this.named().slice(0, ALSO_FOOT_NAMES),
  );
  protected readonly hiddenCount = computed(() => this.named().length - this.shown().length);

  private readonly displayedId = signal<number | null>(null);
  /** The shown copy as the row the store keeps in sync holds it, so an action's optimistic flip and revert show. */
  protected readonly displayed = computed(
    () => this.copies().find((copy) => copy.id === this.displayedId()) ?? null,
  );
  protected readonly origin = signal<HTMLElement | null>(null);
  protected readonly positions = OVERLAY_POSITIONS;
  protected readonly panelId = `dup-popover-${nextId++}`;

  showAll(event: Event): void {
    event.stopPropagation();
    this.expanded.set(true);
  }

  toggle(copy: EntryDto, event: Event): void {
    event.stopPropagation();
    if (this.displayedId() === copy.id) {
      this.close();
      return;
    }
    this.origin.set(event.currentTarget as HTMLElement);
    this.displayedId.set(copy.id);
  }

  open(copy: EntryDto): void {
    this.shellActions.open(copy);
    this.close();
  }

  favorite(copy: EntryDto): void {
    this.shellActions.favorite(copy);
  }

  like(copy: EntryDto): void {
    this.shellActions.like(copy);
  }

  keep(copy: EntryDto): void {
    this.shellActions.keep(copy);
  }

  unshare(copy: EntryDto): void {
    this.shellActions.unshare(copy);
  }

  toggleRead(copy: EntryDto): void {
    this.shellActions.toggleRead(copy);
  }

  hideStory(copy: EntryDto): void {
    this.shellActions.hideStory(copy);
  }

  deletePost(): void {
    this.close();
    this.shellActions.deletePost(this.entry());
  }

  close(): void {
    this.displayedId.set(null);
  }
}
