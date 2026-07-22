// src/app/settings/settings.component.ts
import { Component, OnInit, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../shared/icon/icon.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { TagsStore } from '../reader/tags.store';
import { FeedsSectionComponent } from './feeds-section.component';
import { TagsSectionComponent } from './tags-section.component';
import { OpmlSectionComponent } from './opml-section.component';
import { AccountSectionComponent } from './account-section.component';

@Component({
  selector: 'app-settings',
  imports: [
    RouterLink,
    IconComponent,
    FeedsSectionComponent,
    TagsSectionComponent,
    OpmlSectionComponent,
    AccountSectionComponent,
  ],
  template: `
    <header class="bar">
      <a class="back" routerLink="/"><app-icon name="arrow_back" [size]="18" /> Reader</a>
      <h1>Settings</h1>
    </header>
    <div class="page">
      <app-feeds-section />
      <app-tags-section />
      <app-opml-section />
      <app-account-section />
    </div>
  `,
  styles: [
    `
      .bar {
        height: 56px;
        display: flex;
        align-items: center;
        gap: var(--space-4);
        padding: 0 var(--space-4);
        border-bottom: 1px solid var(--border);
        background: var(--surface-1);
      }
      .back {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        color: var(--text-secondary);
        text-decoration: none;
      }
      h1 {
        font-size: var(--fs-lg);
        margin: 0;
      }
      .page {
        max-width: 820px;
        margin: 0 auto;
        padding: var(--space-5) var(--space-4);
        display: flex;
        flex-direction: column;
        gap: var(--space-6);
      }
    `,
  ],
})
export class SettingsComponent implements OnInit {
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);

  ngOnInit(): void {
    this.subs.load();
    this.tags.load();
  }
}
