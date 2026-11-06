import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { LanguageService } from '../../../core/i18n/language.service';
import { entryRelativeTime } from '../../format';
import { entryDek, entryDisplayTitle, entryHeadline } from '../../entry/entry-text';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SubscriptionRows } from '../../state/subscription-rows';
import { feedAvatarUrl } from '../../state/social-sections';
import { sourceNameOf } from '../../entry/entry-source/source-name';
import { EntryDuplicatesComponent } from './entry-duplicates.component';

let nextId = 0;

@Component({
  selector: 'app-more-sources',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, FaviconComponent, IconComponent, EntryDuplicatesComponent],
  templateUrl: './more-sources.component.html',
  styleUrl: './more-sources.component.scss',
})
export class MoreSourcesComponent {
  readonly entry = input.required<EntryDto>();

  private readonly actions = inject(EntryActionHandler);
  private readonly language = inject(LanguageService);
  private readonly rows = inject(SubscriptionRows);

  protected readonly copies = computed(() => this.entry().duplicates ?? []);
  protected readonly countKey = computed(() =>
    this.copies().length === 1 ? 'reader.moreSourcesOne' : 'reader.moreSources',
  );
  protected readonly sources = computed(() =>
    this.copies().map((copy) => ({
      copy,
      feed: sourceNameOf(copy, this.rows),
      avatarUrl: feedAvatarUrl(this.rows.find(copy.subscriptionId)),
      title: entryHeadline(copy) || entryDisplayTitle(copy),
      dek: entryDek(copy),
      when: entryRelativeTime(copy, this.language.lang()),
    })),
  );
  protected readonly expanded = signal(false);
  protected readonly panelId = `more-sources-${nextId++}`;

  toggle(event: Event): void {
    event.stopPropagation();
    this.expanded.update((open) => !open);
  }

  open(copy: EntryDto, event: Event): void {
    event.stopPropagation();
    this.actions.open(copy);
  }
}
