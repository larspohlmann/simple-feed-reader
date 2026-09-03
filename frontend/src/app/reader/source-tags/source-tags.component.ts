import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { SubscriptionTagDto } from '../models';
import { selectionQueryParams } from '../query';

/**
 * The tag pills shown in front of / below a source name across the reading UI.
 * Each pill links to filter the list by that tag; clicks stop propagating so a
 * pill inside a clickable entry card filters instead of opening it. Renders
 * nothing when the feed carries no tags.
 */
@Component({
  selector: 'app-source-tags',
  imports: [RouterLink, TagGlyphComponent, TranslocoPipe],
  templateUrl: './source-tags.component.html',
  styleUrl: './source-tags.component.scss',
})
export class SourceTagsComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly tags = input.required<SubscriptionTagDto[]>();
}
