// src/app/reader/magazine/source-group.component.ts
import { Component, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { EntryCompactComponent } from './entry-compact.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryDto, SubscriptionTagDto } from '../models';

@Component({
  selector: 'app-source-group',
  imports: [
    RouterLink,
    IconComponent,
    FaviconComponent,
    EntryCompactComponent,
    SourceTagsComponent,
    TranslocoPipe,
  ],
  templateUrl: './source-group.component.html',
  styleUrl: './source-group.component.scss',
})
export class SourceGroupComponent {
  readonly source = input.required<string>();
  readonly subscriptionId = input.required<number>();
  readonly entries = input.required<EntryDto[]>();
  readonly moreCount = input.required<number>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly open = output<EntryDto>();
}
