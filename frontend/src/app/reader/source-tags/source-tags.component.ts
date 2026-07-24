import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { SubscriptionTagDto } from '../models';

/**
 * The tag pills shown in front of / below a source name across the reading UI
 * (entry cards, source groups, the article view). Each pill is a link that
 * filters the list to that tag. Clicks stop propagating so a pill inside a
 * clickable entry card filters instead of opening the entry. Renders nothing
 * when the feed carries no tags.
 */
@Component({
  selector: 'app-source-tags',
  imports: [RouterLink, IconComponent],
  templateUrl: './source-tags.component.html',
  styleUrl: './source-tags.component.scss',
})
export class SourceTagsComponent {
  readonly tags = input.required<SubscriptionTagDto[]>();
}
