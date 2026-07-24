// src/app/settings/settings.component.ts
import { Component, OnInit, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../shared/icon/icon.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { TagsStore } from '../reader/tags.store';
import { FeedsSectionComponent } from './feeds-section.component';
import { TagsSectionComponent } from './tags-section.component';
import { OpmlSectionComponent } from './opml-section.component';
import { AccountSectionComponent } from './account-section.component';
import { PreferencesSectionComponent } from './preferences-section.component';

@Component({
  selector: 'app-settings',
  imports: [
    RouterLink,
    TranslocoPipe,
    IconComponent,
    FeedsSectionComponent,
    TagsSectionComponent,
    OpmlSectionComponent,
    AccountSectionComponent,
    PreferencesSectionComponent,
  ],
  templateUrl: './settings.component.html',
  styleUrl: './settings.component.scss',
})
export class SettingsComponent implements OnInit {
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);

  ngOnInit(): void {
    this.subs.load();
    this.tags.load();
  }
}
