// src/app/app.ts
import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { ErrorBannerComponent } from './shared/error-banner/error-banner.component';
import { NavigationFailureReporter } from './core/navigation-failure';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, ErrorBannerComponent, TranslocoPipe],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  protected readonly navigation = inject(NavigationFailureReporter);

  /**
   * A stalled chunk leaves a pending module promise that the module system
   * caches, so re-navigating would await the same hung fetch and the button
   * would look broken. Only a fresh document gets a fresh module graph.
   */
  protected reload(): void {
    location.reload();
  }
}
