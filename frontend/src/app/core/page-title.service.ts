// src/app/core/page-title.service.ts
import { Injectable, effect, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { Title } from '@angular/platform-browser';
import { TranslocoService } from '@jsverse/transloco';
import { of, switchMap } from 'rxjs';

/** What the current page adds in front of the product name: a translation key
 *  for a page whose name is fixed, or finished text for one that names itself
 *  after its content (the reader). */
type PageName = { readonly key: string } | { readonly text: string } | null;

const BASE_TITLE = 'simple feed reader';

/** The longest page name a browser tab shows before it truncates it itself.
 *  Applied here, not by the page: what fits in a tab is a fact about the tab. */
const NAME_LIMIT = 60;

/** The one writer of `document.title`. Every page states its name here — a page
 *  without one resets to the product name alone — so no title can outlive the
 *  page that set it. */
@Injectable({ providedIn: 'root' })
export class PageTitleService {
  private readonly documentTitle = inject(Title);
  private readonly i18n = inject(TranslocoService);

  private readonly page = signal<PageName>(null, { equal: sameName });

  /** `selectTranslate`, not `translate`: it waits for the dictionary instead of
   *  echoing the key back while it is still loading, and it re-emits on a
   *  language switch, which keeps the tab in the language of the UI. */
  private readonly name = toSignal(
    toObservable(this.page).pipe(
      switchMap((page) => {
        if (page === null) return of(null);
        return 'text' in page ? of(page.text) : this.i18n.selectTranslate<string>(page.key);
      }),
    ),
    { initialValue: null },
  );

  constructor() {
    effect(() => this.documentTitle.setTitle(compose(this.name())));
  }

  /** Name the page by translation key — the form every static page uses. */
  useKey(key: string): void {
    this.page.set({ key });
  }

  /** Name the page with text it produced itself, already translated — a
   *  headline may arrive at full length, the tab cut is taken here. */
  useText(text: string): void {
    this.page.set({ text });
  }

  /** Drop back to the product name alone. */
  reset(): void {
    this.page.set(null);
  }
}

function compose(name: string | null): string {
  const shown = cutToTab(name ?? '');
  if (shown === '' || shown === BASE_TITLE) return BASE_TITLE;
  return `${shown} | ${BASE_TITLE}`;
}

function cutToTab(name: string): string {
  if (name.length <= NAME_LIMIT) return name;
  return `${name.slice(0, NAME_LIMIT)}…`;
}

function sameName(a: PageName, b: PageName): boolean {
  if (a === null || b === null) return a === b;
  if ('text' in a) return 'text' in b && a.text === b.text;
  return 'key' in b && a.key === b.key;
}
