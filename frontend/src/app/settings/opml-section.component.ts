// src/app/settings/opml-section.component.ts
import { Component, inject, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ReaderApi } from '../reader/reader-api';
import { RefreshService } from '../reader/refresh.service';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { OpmlImportResult } from '../reader/models';
import { ButtonComponent } from '../shared/button/button.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';

@Component({
  selector: 'app-opml-section',
  imports: [ButtonComponent, ErrorBannerComponent, SettingsCardComponent, TranslocoPipe],
  templateUrl: './opml-section.component.html',
  styleUrl: './opml-section.component.scss',
})
export class OpmlSectionComponent {
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);
  private readonly refresh = inject(RefreshService);

  readonly text = signal('');
  readonly reading = signal(false);
  readonly exporting = signal(false);
  readonly importing = signal(false);
  readonly result = signal<OpmlImportResult | null>(null);
  readonly exportFailed = signal(false);
  readonly importFailed = signal(false);

  value(e: Event): string {
    return (e.target as HTMLTextAreaElement).value;
  }

  exportOpml(): void {
    this.exporting.set(true);
    this.exportFailed.set(false);
    this.api.exportOpml().subscribe({
      next: (xml) => {
        this.exporting.set(false);
        this.download(xml);
      },
      error: () => {
        this.exporting.set(false);
        this.exportFailed.set(true);
      },
    });
  }

  onFile(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    // Block Import until the async read resolves, so a quick click cannot send
    // stale pasted text instead of the chosen file.
    this.reading.set(true);
    file.text().then((t) => {
      this.text.set(t);
      this.reading.set(false);
    });
  }

  importText(): void {
    const body = this.text().trim();
    if (!body) return;
    this.importing.set(true);
    this.importFailed.set(false);
    this.result.set(null);
    this.api.importOpml(body).subscribe({
      next: (r) => {
        this.importing.set(false);
        this.result.set(r);
        this.subs.load();
        // Freshly imported feeds are due but empty until a fetch runs. Kick a
        // refresh now so they populate without waiting for a manual one; reload
        // the list as it progresses so unread counts fill in.
        if (r.imported > 0) {
          this.refresh.run(() => this.subs.load());
        }
      },
      error: () => {
        this.importing.set(false);
        this.importFailed.set(true);
      },
    });
  }

  private download(xml: string): void {
    const url = URL.createObjectURL(new Blob([xml], { type: 'text/x-opml' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'feeds.opml';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
    // Revoke on the next tick: Firefox/Safari queue the download asynchronously
    // and read the blob after click() returns, so revoking synchronously can
    // yield an empty file.
    setTimeout(() => URL.revokeObjectURL(url), 0);
  }
}
