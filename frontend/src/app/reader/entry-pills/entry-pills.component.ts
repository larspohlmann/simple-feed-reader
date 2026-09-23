import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { SavedSearchMembershipDto, SubscriptionTagDto } from '../models';
import { selectionQueryParams } from '../query';

/**
 * Tag pills, then saved-search pills, as one wrapping list. Clicks stop
 * propagating so a pill inside a clickable card follows its link instead.
 */
@Component({
  selector: 'app-entry-pills',
  imports: [RouterLink, TranslocoPipe, IconComponent, TagGlyphComponent],
  templateUrl: './entry-pills.component.html',
  styleUrl: './entry-pills.component.scss',
})
export class EntryPillsComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly tags = input.required<SubscriptionTagDto[]>();
  readonly savedSearches = input<SavedSearchMembershipDto[]>([]);
}
