// src/app/core/page-title.service.ts
import { Injectable, effect, inject, signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { Title } from '@angular/platform-browser';
import { TranslocoService } from '@jsverse/transloco';
import { map, of, switchMap } from 'rxjs';

/** What the current page adds in front of the product name: a translation key
 *  for a page whose name is fixed, or finished text for one that names itself
 *  after its content (the reader). A page that names itself after a list also
 *  says how much is in it; a page with a fixed name has nothing to count. */
type PageName = { readonly key: string } | { readonly text: string; readonly count: number } | null;

/** A page name with its count resolved — what the tab is composed from. */
interface ResolvedName {
  readonly text: string;
  readonly count: number;
}

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
  private readonly name = toSignal<ResolvedName | null>(
    toObservable(this.page).pipe(
      switchMap((page) => {
        if (page === null) return of(null);
        if ('text' in page) return of({ text: page.text, count: page.count });
        return this.i18n
          .selectTranslate<string>(page.key)
          .pipe(map((text) => ({ text, count: 0 })));
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
   *  headline may arrive at full length, the tab cut is taken here. `count` is
   *  what the named list holds; zero says the page has nothing to count, which
   *  covers both an empty list and a page (an open article) that is not one. */
  useText(text: string, count = 0): void {
    this.page.set({ text, count });
  }

  /** Drop back to the product name alone. */
  reset(): void {
    this.page.set(null);
  }
}

function compose(name: ResolvedName | null): string {
  const shown = cutToTab(name?.text ?? '');
  if (shown === '' || shown === BASE_TITLE) return BASE_TITLE;
  return `${shown}${countSuffix(name?.count ?? 0)} | ${BASE_TITLE}`;
}

/** The count trails the NAME CUT, not the name: a feed whose title overruns the
 *  tab would otherwise lose the very number this suffix exists to show. Nothing
 *  to count renders nothing — an empty list reads as its name alone, the way
 *  the sidebar drops the badge rather than showing a zero. */
function countSuffix(count: number): string {
  return count > 0 ? ` (${count})` : '';
}

function cutToTab(name: string): string {
  if (name.length <= NAME_LIMIT) return name;
  return `${name.slice(0, NAME_LIMIT)}…`;
}

function sameName(a: PageName, b: PageName): boolean {
  if (a === null || b === null) return a === b;
  if ('text' in a) return 'text' in b && a.text === b.text && a.count === b.count;
  return 'key' in b && a.key === b.key;
}
