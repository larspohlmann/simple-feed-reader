import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { LanguageService } from '../../../core/i18n/language.service';
import { SubscriptionRows } from '../../state/subscription-rows';
import { sourceNameOf } from '../../entry/entry-source/source-name';
import { entryDisplayTitle, entryHeadline } from '../../entry/entry-text';

/** The newest sharers a line names before it counts the rest. */
const NAMED_SHARERS = 2;

let nextId = 0;

/** "Shared by Ann, Ben and 3 others" under a Trending article, opening into each sharing post (#2229). */
@Component({
  selector: 'app-story-shares',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe],
  templateUrl: './story-shares.component.html',
  styleUrl: './story-shares.component.scss',
})
export class StorySharesComponent {
  readonly entry = input.required<EntryDto>();

  private readonly actions = inject(EntryActionHandler);
  private readonly language = inject(LanguageService);
  private readonly rows = inject(SubscriptionRows);

  protected readonly shares = computed(() =>
    (this.entry().shares ?? []).map((share) => ({
      share,
      poster: sourceNameOf(share, this.rows),
      text: entryHeadline(share) || entryDisplayTitle(share),
    })),
  );
  private readonly others = computed(() => Math.max(0, this.shares().length - NAMED_SHARERS));
  protected readonly lineKey = computed(() => {
    if (this.others() === 0) return 'reader.sharedBy';
    return this.others() === 1 ? 'reader.sharedByAndOne' : 'reader.sharedByAndOthers';
  });
  protected readonly line = computed(() => {
    const names = this.shares()
      .slice(0, NAMED_SHARERS)
      .map((share) => share.poster);
    return {
      names: this.others() === 0 ? this.listOf(names) : names.join(', '),
      count: this.others(),
    };
  });
  protected readonly expanded = signal(false);
  protected readonly panelId = `story-shares-${nextId++}`;

  toggle(event: Event): void {
    event.stopPropagation();
    this.expanded.update((open) => !open);
  }

  open(share: EntryDto, event: Event): void {
    event.stopPropagation();
    this.actions.open(share);
  }

  private listOf(names: string[]): string {
    return new Intl.ListFormat(this.language.lang(), { type: 'conjunction' }).format(names);
  }
}
