// src/app/settings/opml-section.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { parseProblem } from '../core/problem';
import { ReaderApi } from '../reader/reader-api';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { OpmlImportResult } from '../reader/models';

@Component({
  selector: 'app-opml-section',
  template: `
    <section>
      <h2>Import &amp; export</h2>

      <div class="block">
        <p class="lead">Download all your feeds as an OPML file.</p>
        <button class="btn" [disabled]="exporting()" (click)="exportOpml()">
          {{ exporting() ? 'Preparing…' : 'Export OPML' }}
        </button>
        @if (exportError()) {
          <p class="error" role="alert">{{ exportError() }}</p>
        }
      </div>

      <div class="block">
        <p class="lead">Import an OPML file, or paste its contents.</p>
        <input type="file" accept=".opml,.xml,text/xml,text/x-opml" (change)="onFile($event)" />
        <textarea
          class="field area"
          rows="4"
          placeholder="…or paste OPML here"
          [value]="text()"
          (input)="text.set(value($event))"
        ></textarea>
        <button
          class="btn primary"
          [disabled]="importing() || !text().trim()"
          (click)="importText()"
        >
          {{ importing() ? 'Importing…' : 'Import' }}
        </button>
        @if (importError()) {
          <p class="error" role="alert">{{ importError() }}</p>
        }
        @if (result(); as r) {
          <p class="result">
            Imported {{ r.imported }}, already subscribed {{ r.alreadySubscribed }}, invalid
            {{ r.invalid }}, skipped over limit {{ r.skippedOverLimit }}. New feeds fill in on the
            next refresh.
          </p>
        }
      </div>
    </section>
  `,
  styles: [
    `
      h2 {
        font-size: var(--fs-lg);
        margin: 0 0 var(--space-3);
      }
      .block {
        padding: var(--space-4);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
        margin-bottom: var(--space-3);
        display: flex;
        flex-direction: column;
        gap: var(--space-2);
        align-items: flex-start;
      }
      .lead {
        margin: 0;
        color: var(--text-secondary);
      }
      .area {
        width: 100%;
        resize: vertical;
        font-family: var(--font-sans);
      }
      .btn {
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .btn.primary {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
      .btn:disabled {
        opacity: 0.7;
        cursor: default;
      }
      .error {
        color: var(--danger);
        font-size: var(--fs-sm);
        margin: 0;
      }
      .result {
        margin: 0;
        color: var(--text-secondary);
        font-size: var(--fs-sm);
      }
    `,
  ],
})
export class OpmlSectionComponent {
  private readonly api = inject(ReaderApi);
  private readonly subs = inject(SubscriptionsStore);

  readonly text = signal('');
  readonly exporting = signal(false);
  readonly importing = signal(false);
  readonly result = signal<OpmlImportResult | null>(null);
  readonly exportError = signal<string | null>(null);
  readonly importError = signal<string | null>(null);

  value(e: Event): string {
    return (e.target as HTMLTextAreaElement).value;
  }

  exportOpml(): void {
    this.exporting.set(true);
    this.exportError.set(null);
    this.api.exportOpml().subscribe({
      next: (xml) => {
        this.exporting.set(false);
        this.download(xml);
      },
      error: (e: HttpErrorResponse) => {
        this.exporting.set(false);
        this.exportError.set(parseProblem(e).title);
      },
    });
  }

  onFile(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    file.text().then((t) => this.text.set(t));
  }

  importText(): void {
    const body = this.text().trim();
    if (!body) return;
    this.importing.set(true);
    this.importError.set(null);
    this.result.set(null);
    this.api.importOpml(body).subscribe({
      next: (r) => {
        this.importing.set(false);
        this.result.set(r);
        this.subs.load();
      },
      error: (e: HttpErrorResponse) => {
        this.importing.set(false);
        this.importError.set(parseProblem(e).detail ?? parseProblem(e).title);
      },
    });
  }

  private download(xml: string): void {
    const url = URL.createObjectURL(new Blob([xml], { type: 'text/x-opml' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'feeds.opml';
    a.click();
    URL.revokeObjectURL(url);
  }
}
