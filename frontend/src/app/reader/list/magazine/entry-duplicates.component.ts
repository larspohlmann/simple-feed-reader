import { Component, computed, forwardRef, inject, input, signal } from '@angular/core';
import { CdkConnectedOverlay, ConnectedPosition } from '@angular/cdk/overlay';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { VoteValue } from '../../vote-pill/vote';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { CompactCount } from '../../../core/i18n/compact-count';
import { buildDiscussionCount, DiscussionCount } from '../../../core/i18n/discussion-count';
import { LanguageService } from '../../../core/i18n/language.service';
import { entryRelativeTime } from '../../format';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SubscriptionRows } from '../../state/subscription-rows';
import { MENU_VIEWPORT_MARGIN } from '../../../shared/menu-keys';
import { alsoFootOf, isCrossPost } from './also-foot';
import { formatCommunityLabel, sourceNameOf } from '../../entry/entry-source/source-name';
import { StoryActionsComponent } from '../../trending/story-actions/story-actions.component';
import { STORY_ICON } from '../../trending/story';
import { injectStory, StoryContextDirective } from '../../trending/story-context';

let nextId = 0;

const OVERLAY_POSITIONS: ConnectedPosition[] = [
  { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 6 },
  { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
  { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -6 },
  { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
];

interface ChipCount extends DiscussionCount {
  icon: 'arrow_upward' | 'chat_bubble';
}

type AlsoChip =
  | { crossPost: true; copy: EntryDto; feed: string; counts: ChipCount[] }
  | { crossPost: false; copy: EntryDto; feed: string; when: string };

/** The footer's chip limit: a capped footer keeps a card's height predictable for the planner (#2017). */
export const ALSO_FOOT_NAMES = 4;

@Component({
  selector: 'app-entry-duplicates',
  // forwardRef: entry-row renders entry-duplicates for its own footer, so a
  // plain reference here would resolve EntryRowComponent mid-import-cycle and
  // read as undefined, depending on which of the two loads first.
  imports: [
    TranslocoPipe,
    IconComponent,
    CdkConnectedOverlay,
    StoryActionsComponent,
    StoryContextDirective,
    forwardRef(() => EntryRowComponent),
  ],
  templateUrl: './entry-duplicates.component.html',
  styleUrl: './entry-duplicates.component.scss',
  providers: [
    { provide: EntryActionHandler, useExisting: forwardRef(() => EntryDuplicatesComponent) },
  ],
})
export class EntryDuplicatesComponent implements EntryActionHandler {
  /** The row the footer sits on; deleting from a copy's card deletes this row's post (#1972). */
  readonly entry = input.required<EntryDto>();
  protected readonly story = injectStory();
  private readonly foot = computed(() => alsoFootOf(this.entry(), this.story()));
  protected readonly viewportMargin = MENU_VIEWPORT_MARGIN;
  protected readonly copies = computed(() => this.foot().copies);
  protected readonly label = computed(() => this.foot().label);
  protected readonly icon = computed(() => (this.story() ? STORY_ICON : 'layers'));
  private readonly shellActions = inject(EntryActionHandler, { skipSelf: true });

  private readonly language = inject(LanguageService);
  private readonly rows = inject(SubscriptionRows);
  private readonly compactCount = inject(CompactCount);
  private readonly named = computed((): AlsoChip[] =>
    this.copies().map((copy) => {
      const community = isCrossPost(this.entry(), copy) ? copy.community : null;
      return community
        ? {
            crossPost: true,
            copy,
            feed: formatCommunityLabel(community),
            counts: this.buildCounts(copy),
          }
        : {
            crossPost: false,
            copy,
            feed: this.formatFeedName(copy),
            when: entryRelativeTime(copy, this.language.lang()),
          };
    }),
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

  openCrossPost(copy: EntryDto, event: Event): void {
    event.stopPropagation();
    this.open(copy);
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

  isLikeOnTheWire(copy: EntryDto): boolean {
    return this.shellActions.isLikeOnTheWire(copy);
  }

  findVoteOnTheWire(copy: EntryDto): 'up' | 'down' | null {
    return this.shellActions.findVoteOnTheWire(copy);
  }

  vote(copy: EntryDto, to: VoteValue): void {
    this.shellActions.vote(copy, to);
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

  deletePost(): void {
    this.close();
    this.shellActions.deletePost(this.entry());
  }

  close(): void {
    this.displayedId.set(null);
  }

  private formatFeedName(copy: EntryDto): string {
    return this.story()
      ? sourceNameOf(copy, this.rows)
      : (this.rows.find(copy.subscriptionId)?.title ?? copy.source);
  }

  private buildCounts(copy: EntryDto): ChipCount[] {
    const counts: ChipCount[] = [];
    const score = buildDiscussionCount(
      this.compactCount,
      copy.score,
      'reader.discussionCounts.points',
    );
    if (score) counts.push({ ...score, icon: 'arrow_upward' });
    const comments = buildDiscussionCount(
      this.compactCount,
      copy.commentCount,
      'reader.discussionCounts.comments',
    );
    if (comments) counts.push({ ...comments, icon: 'chat_bubble' });
    return counts;
  }
}
