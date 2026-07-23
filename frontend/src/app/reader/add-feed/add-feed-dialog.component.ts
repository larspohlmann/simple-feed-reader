// src/app/reader/add-feed/add-feed-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef } from '@angular/cdk/dialog';
import { parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { FeedCandidate, FeedPreview, SubscriptionDto } from '../models';

type PreviewState =
  | { status: 'loading' }
  | { status: 'error' }
  | { status: 'ok'; preview: FeedPreview };

@Component({
  selector: 'app-add-feed-dialog',
  imports: [ReactiveFormsModule, A11yModule],
  template: `
    <div class="dialog" role="dialog" aria-modal="true" aria-label="Add a feed" cdkTrapFocus>
      <h2>Add a feed</h2>
      <form [formGroup]="form" (ngSubmit)="submit()">
        <input
          class="field"
          formControlName="url"
          type="url"
          placeholder="https://example.com"
          aria-label="Feed or site URL"
          cdkFocusInitial
        />
        @if (error()) {
          <p class="error" role="alert">{{ error() }}</p>
        }
        @if (candidates().length) {
          <p class="hint">We found these feeds — pick one:</p>
          <ul class="candidates">
            @for (c of candidates(); track c.url) {
              @let state = previews()[c.url];
              @let p = okPreview(state);
              <li class="card">
                <div class="card-head">
                  <span class="card-title">{{ p?.title || c.title || c.url }}</span>
                  @if (p) {
                    <span class="count">{{ p.itemCount }} items</span>
                  }
                </div>
                @if (state?.status === 'loading') {
                  <p class="muted">Loading preview…</p>
                } @else if (state?.status === 'error') {
                  <p class="muted">Preview unavailable</p>
                } @else if (p) {
                  <div class="badges">
                    <span class="badge">{{ contentLabel(p.content) }}</span>
                    <span class="badge">{{ p.hasImages ? 'With images' : 'No images' }}</span>
                  </div>
                  @if (p.items.length) {
                    <ul class="samples">
                      @for (it of p.items.slice(0, 3); track $index) {
                        <li>{{ it.title }}</li>
                      }
                    </ul>
                  } @else {
                    <p class="muted">No recent items</p>
                  }
                }
                <button type="button" class="subscribe primary" (click)="pick(c.url)">
                  Subscribe
                </button>
              </li>
            }
          </ul>
        }
        @if (searched() && candidates().length === 0) {
          <p class="hint">No feeds found at that address.</p>
        }
        <div class="row">
          <button type="button" (click)="ref.close()">Cancel</button>
          <!-- Once candidates are shown, each card carries its own Subscribe
               button, so the footer submit would be a confusing third action.
               It only makes sense before a search (and to retry when none were
               found). -->
          @if (!candidates().length) {
            <button type="submit" class="primary" [disabled]="loading()">
              {{ loading() ? 'Adding…' : 'Add' }}
            </button>
          }
        </div>
      </form>
    </div>
  `,
  styles: [
    `
      .dialog {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: var(--space-5);
        width: min(440px, 92vw);
        display: flex;
        flex-direction: column;
        gap: var(--space-3);
      }
      h2 {
        margin: 0;
        font-size: var(--fs-lg);
      }
      .error {
        color: var(--danger);
        font-size: var(--fs-sm);
        margin: 0;
      }
      .hint {
        color: var(--text-secondary);
        font-size: var(--fs-sm);
        margin: 0;
      }
      .candidates {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .card {
        display: flex;
        flex-direction: column;
        gap: var(--space-2);
        padding: var(--space-3);
        background: var(--surface-1);
        border: 1px solid var(--border);
        border-radius: var(--radius);
      }
      .card-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: var(--space-2);
      }
      .card-title {
        font-weight: 600;
        color: var(--text-primary);
        overflow-wrap: anywhere;
      }
      .count {
        flex: none;
        font-size: var(--fs-sm);
        color: var(--text-secondary);
        white-space: nowrap;
      }
      .badges {
        display: flex;
        flex-wrap: wrap;
        gap: var(--space-1);
      }
      .badge {
        font-size: var(--fs-sm);
        padding: 0 var(--space-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        color: var(--text-secondary);
      }
      .samples {
        list-style: disc;
        margin: 0;
        padding-left: var(--space-4);
        font-size: var(--fs-sm);
        color: var(--text-secondary);
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .muted {
        color: var(--text-secondary);
        font-size: var(--fs-sm);
        margin: 0;
      }
      .subscribe {
        align-self: flex-start;
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--accent);
        border-radius: var(--radius);
        background: var(--accent);
        color: var(--on-accent);
        cursor: pointer;
      }
      .row {
        display: flex;
        justify-content: flex-end;
        gap: var(--space-2);
      }
      .row button {
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .row button.primary {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
    `,
  ],
})
export class AddFeedDialogComponent {
  readonly ref = inject<DialogRef<SubscriptionDto>>(DialogRef);
  private readonly api = inject(ReaderApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly form = this.fb.group({ url: ['', [Validators.required]] });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly candidates = signal<FeedCandidate[]>([]);
  readonly searched = signal(false);
  readonly previews = signal<Record<string, PreviewState>>({});

  okPreview(state: PreviewState | undefined): FeedPreview | null {
    return state?.status === 'ok' ? state.preview : null;
  }

  contentLabel(content: FeedPreview['content']): string {
    return content === 'full' ? 'Full text' : content === 'summary' ? 'Summary only' : 'Titles only';
  }

  submit(): void {
    if (this.form.invalid) return;
    this.subscribe(this.form.getRawValue().url);
  }

  pick(url: string): void {
    this.subscribe(url);
  }

  private subscribe(url: string): void {
    this.loading.set(true);
    this.error.set(null);
    this.searched.set(false);
    this.api.subscribe(url).subscribe({
      next: (res) => {
        this.loading.set(false);
        if ('subscription' in res) this.ref.close(res.subscription);
        else {
          this.candidates.set(res.candidates);
          this.searched.set(true);
          this.loadPreviews(res.candidates);
        }
      },
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        const p = parseProblem(e);
        this.error.set(p.errors?.['url']?.[0] ?? p.detail ?? p.title);
      },
    });
  }

  private loadPreviews(candidates: FeedCandidate[]): void {
    this.previews.set(Object.fromEntries(candidates.map((c) => [c.url, { status: 'loading' }])));
    for (const c of candidates) {
      this.api.previewFeed(c.url).subscribe({
        next: (r) => this.previews.update((m) => ({ ...m, [c.url]: { status: 'ok', preview: r.feed } })),
        error: () => this.previews.update((m) => ({ ...m, [c.url]: { status: 'error' } })),
      });
    }
  }
}
