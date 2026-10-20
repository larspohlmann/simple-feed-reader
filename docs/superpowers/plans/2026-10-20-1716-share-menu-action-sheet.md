# Share menu: the action sheet on coarse pointers (#1716) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a coarse pointer the article's Share button opens the shared bottom action sheet with the same entries, logos and dividers as the desktop popover; fine pointers keep the popover.

**Architecture:** The share button branches on `LayoutService.isCoarse()` in its click handler, the way the sidebar rows and the Organise page already do: coarse opens the `ActionSheet` service, fine toggles the CDK popover. The action sheet learns what #1699 asked for, an optional symbol or logo per action and a divider entry, and stays feature-agnostic (pre-translated labels, a full logo URL). The brand-logo mask the popover draws inline moves into one shared `<app-masked-logo>` that both surfaces use. `DialogRef.close` emits `closed` synchronously inside the sheet item's click, so `navigator.share` and the clipboard keep their user gesture.

**Tech Stack:** Angular 20 (standalone, signals, OnPush), `@angular/cdk/dialog`, Jest (jsdom), SCSS with theme tokens.

**Spec:** GitHub issue #1716 (`gh issue view 1716`); the menu shape comes from #1699.

## Global Constraints

- Component styles in the sibling `.scss`; no hex colours there, no ad-hoc `px`. A brand colour arrives as a value from `share-targets.json` and lands in an inline style, as today.
- `shared/` knows no feature i18n keys and no feature paths: labels arrive translated, logos as a full URL.
- Comments: default to none; one line when a future reader would get it wrong without it.
- Frontend tests run in the container: `docker compose exec -T frontend npx jest <paths>`; the gate is `cd frontend && npm run check`. Never two Jest processes at once in the container.
- Commits: `fix(#1716): …` / `refactor(#1716): …`, no attribution lines.

## File Structure

| File | Responsibility |
|---|---|
| Create `frontend/src/app/shared/masked-logo/masked-logo.component.{ts,html,scss,spec.ts}` | `<app-masked-logo [src] [color]>`: an SVG file drawn as a coloured mask at `--icon-sm`, hidden from assistive technology |
| Modify `frontend/src/app/shared/action-sheet/action-sheet.component.{ts,html,scss}` | `symbol` / `logo` per action, `ACTION_SHEET_DIVIDER` entries, `isDivider` guard |
| Modify `frontend/src/app/shared/action-sheet/action-sheet.service.spec.ts` | Specs for symbol, logo and divider rendering; separators allowed among the menu's children |
| Modify `frontend/src/app/reader/article/share/share-targets.ts` | `shareLogoUrl(file)`: the one place that knows the logo folder |
| Modify `frontend/src/app/reader/article/share/share-button.component.{ts,html,scss,spec.ts}` | Coarse branch opening the sheet; popover draws logos through `<app-masked-logo>` |
| Modify `docs/design-language.md` | `<app-masked-logo>` section; action-sheet section gains the icon and divider options |

---

### Task 1: `<app-masked-logo>`

**Files:**
- Create: `frontend/src/app/shared/masked-logo/masked-logo.component.ts`
- Create: `frontend/src/app/shared/masked-logo/masked-logo.component.html` (empty element, host-styled)
- Create: `frontend/src/app/shared/masked-logo/masked-logo.component.scss`
- Create: `frontend/src/app/shared/masked-logo/masked-logo.component.spec.ts`

**Interfaces:**
- Produces: `MaskedLogoComponent` with `src = input.required<string>()` and `color = input<string>()`; the host carries `aria-hidden="true"`, `style.background-color` = `color()` when given, `style.mask-image` and `style.-webkit-mask-image` = `url(<src>)`.

- [ ] **Step 1: Write the failing spec**

```ts
import { TestBed } from '@angular/core/testing';
import { MaskedLogoComponent } from './masked-logo.component';

function render(color?: string): HTMLElement {
  const fixture = TestBed.createComponent(MaskedLogoComponent);
  fixture.componentRef.setInput('src', 'share-targets/bluesky.svg');
  fixture.componentRef.setInput('color', color);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('MaskedLogoComponent', () => {
  it('masks the host with the file, hidden from assistive technology', () => {
    const host = render();

    expect(host.style.maskImage).toBe('url(share-targets/bluesky.svg)');
    expect(host.getAttribute('aria-hidden')).toBe('true');
    expect(host.style.backgroundColor).toBe('');
  });

  it('fills the mask with the given colour', () => {
    expect(render('rgb(17, 133, 254)').style.backgroundColor).toBe('rgb(17, 133, 254)');
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/shared/masked-logo`
Expected: FAIL — `Cannot find module './masked-logo.component'`.

- [ ] **Step 3: Implement the component**

`masked-logo.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/** An SVG file drawn as a coloured mask at `--icon-sm`; the label beside it carries the meaning. */
@Component({
  selector: 'app-masked-logo',
  templateUrl: './masked-logo.component.html',
  styleUrl: './masked-logo.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    'aria-hidden': 'true',
    '[style.background-color]': 'color()',
    '[style.mask-image]': 'maskImage()',
    '[style.-webkit-mask-image]': 'maskImage()',
  },
})
export class MaskedLogoComponent {
  readonly src = input.required<string>();
  readonly color = input<string>();

  protected readonly maskImage = computed(() => `url(${this.src()})`);
}
```

`masked-logo.component.html`: empty file.

`masked-logo.component.scss`:

```scss
:host {
  display: inline-block;
  flex: 0 0 auto;
  width: var(--icon-sm);
  height: var(--icon-sm);
  background-color: var(--text-primary);
  mask-repeat: no-repeat;
  mask-position: center;
  mask-size: contain;
}
```

- [ ] **Step 4: Run the spec to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/shared/masked-logo`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/shared/masked-logo
git commit -m "feat(#1716): one shared masked logo for brand marks drawn from a file"
```

---

### Task 2: Action sheet with symbols, logos and dividers

**Files:**
- Modify: `frontend/src/app/shared/action-sheet/action-sheet.component.ts`
- Modify: `frontend/src/app/shared/action-sheet/action-sheet.component.html`
- Modify: `frontend/src/app/shared/action-sheet/action-sheet.component.scss`
- Modify: `frontend/src/app/shared/action-sheet/action-sheet.service.spec.ts`
- Modify: `docs/design-language.md` (the `<app-action-sheet>` section; a new `<app-masked-logo>` section after `<app-icon>`)

**Interfaces:**
- Produces:
  - `interface ActionSheetLogo { url: string; color?: string }`
  - `ActionSheetAction` gains `symbol?: string` and `logo?: ActionSheetLogo`
  - `const ACTION_SHEET_DIVIDER: { readonly divider: true }`, `type ActionSheetDivider = typeof ACTION_SHEET_DIVIDER`
  - `type ActionSheetItem = ActionSheetAction | ActionSheetDivider`
  - `ActionSheetData.actions: readonly ActionSheetItem[]`
  - `isDivider(item: ActionSheetItem): item is ActionSheetDivider`

- [ ] **Step 1: Write the failing specs**

Add to `action-sheet.service.spec.ts`, inside `describe('ActionSheet')`:

```ts
  it('draws a symbol or a logo before the label and a divider between groups', () => {
    open({
      title: 'Share',
      actions: [
        { id: 'copy', label: 'Copy link', symbol: 'link' },
        ACTION_SHEET_DIVIDER,
        {
          id: 'bluesky',
          label: 'Bluesky',
          logo: { url: 'share-targets/bluesky.svg', color: 'rgb(17, 133, 254)' },
        },
      ],
    }).subscribe();

    const menu = container.querySelector('[role=menu]')!;
    expect(items()[0].querySelector('app-icon')).not.toBeNull();
    expect(menu.querySelector('[role=separator]')).not.toBeNull();
    const logo = items()[1].querySelector<HTMLElement>('app-masked-logo')!;
    expect(logo.style.maskImage).toBe('url(share-targets/bluesky.svg)');
    expect(logo.style.backgroundColor).toBe('rgb(17, 133, 254)');
  });
```

Import `ACTION_SHEET_DIVIDER` from `./action-sheet.component`. Relax the "menu items only" spec so a separator is also a legitimate child:

```ts
    for (const child of menuChildren) {
      expect(['menuitem', 'separator']).toContain(child.getAttribute('role'));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/shared/action-sheet`
Expected: FAIL — `ACTION_SHEET_DIVIDER` is not exported.

- [ ] **Step 3: Implement**

`action-sheet.component.ts`: replace the `ActionSheetAction` / `ActionSheetData` block with

```ts
export interface ActionSheetLogo {
  url: string;
  color?: string;
}

/** One choice on the sheet. `label` arrives already translated — this lives in
 *  shared/ and must not know any feature's i18n keys. */
export interface ActionSheetAction {
  id: string;
  label: string;
  symbol?: string;
  logo?: ActionSheetLogo;
  danger?: boolean;
  disabled?: boolean;
}

export const ACTION_SHEET_DIVIDER = { divider: true } as const;
export type ActionSheetDivider = typeof ACTION_SHEET_DIVIDER;
export type ActionSheetItem = ActionSheetAction | ActionSheetDivider;

/** What the sheet shows: the row it acts on, and that row's actions with the dividers between their groups. */
export interface ActionSheetData {
  title: string;
  actions: readonly ActionSheetItem[];
}
```

Add `imports: [IconComponent, MaskedLogoComponent]` to the component decorator and

```ts
  protected isDivider(item: ActionSheetItem): item is ActionSheetDivider {
    return 'divider' in item;
  }
```

`action-sheet.component.html`:

```html
<div class="grabber" aria-hidden="true"></div>
<p class="title" aria-hidden="true">{{ data.title }}</p>
<nav role="menu" [attr.aria-label]="data.title">
  @for (item of data.actions; track $index) {
    @if (isDivider(item)) {
      <hr role="separator" />
    } @else {
      <button
        role="menuitem"
        type="button"
        [class.danger]="item.danger"
        [disabled]="item.disabled"
        (click)="ref.close(item.id)"
      >
        @if (item.logo) {
          <app-masked-logo [src]="item.logo.url" [color]="item.logo.color" />
        } @else if (item.symbol) {
          <app-icon [name]="item.symbol" size="sm" />
        }
        {{ item.label }}
      </button>
    }
  }
</nav>
```

`action-sheet.component.scss`: `nav button` gains `gap: var(--space-3);`; add

```scss
nav app-icon {
  color: var(--text-secondary);
}

hr {
  border: none;
  border-block-start: 1px solid var(--border);
  margin: var(--space-1) var(--row-pad-x);
}
```

`docs/design-language.md`: in the `<app-action-sheet>` section, after the labels paragraph, add that an action may carry `symbol` (a Material symbol) or `logo: { url, color? }` (drawn through `<app-masked-logo>`), and that `ACTION_SHEET_DIVIDER` in `actions` renders a separator. After the `<app-icon>` section add:

```markdown
### `<app-masked-logo>`

An SVG file drawn as a coloured mask, sized `--icon-sm`, hidden from assistive
technology: the label beside it carries the meaning.

| Input | Type | Default |
|---|---|---|
| `src` | `string` (required) | — |
| `color` | `string` | `var(--text-primary)` |

```html
<app-masked-logo src="share-targets/bluesky.svg" color="#1185fe" />
```

**Not for:** a connected social network's mark, which is `<app-network-logo>`
with its inline path and brand token.
```

- [ ] **Step 4: Run the specs to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/shared/action-sheet`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/shared/action-sheet docs/design-language.md
git commit -m "feat(#1716): action sheet items carry a symbol or a logo, and a divider separates groups"
```

---

### Task 3: The share button opens the sheet on a coarse pointer

**Files:**
- Modify: `frontend/src/app/reader/article/share/share-targets.ts`
- Modify: `frontend/src/app/reader/article/share/share-button.component.ts`
- Modify: `frontend/src/app/reader/article/share/share-button.component.html`
- Modify: `frontend/src/app/reader/article/share/share-button.component.scss`
- Modify: `frontend/src/app/reader/article/share/share-button.component.spec.ts`

**Interfaces:**
- Consumes: `ActionSheet.open(data): Observable<string | undefined>`, `ACTION_SHEET_DIVIDER`, `ActionSheetItem`, `LayoutService.isCoarse: Signal<boolean>`, `<app-masked-logo>`.
- Produces: `shareLogoUrl(file: string): string` in `share-targets.ts`.

- [ ] **Step 1: Write the failing specs**

Rework `share-button.component.spec.ts` so the module is built per test with a pointer kind and a sheet double:

```ts
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { LayoutService } from '../../layout.service';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import {
  ActionSheetAction,
  ActionSheetData,
  ActionSheetItem,
} from '../../../shared/action-sheet/action-sheet.component';
import { ToastService } from '../../../shared/toast/toast.service';
import { ShareButtonComponent } from './share-button.component';

describe('ShareButtonComponent', () => {
  const toast = { show: jest.fn() };
  const sheet = { open: jest.fn() };
  let chosen: Subject<string | undefined>;
  let fixture: ComponentFixture<ShareButtonComponent>;

  function mount(coarse: boolean): void {
    chosen = new Subject();
    sheet.open.mockReturnValue(chosen.asObservable());
    TestBed.configureTestingModule({
      imports: [ShareButtonComponent, provideTranslocoTesting()],
      providers: [
        { provide: ToastService, useValue: toast },
        { provide: ActionSheet, useValue: sheet },
        { provide: LayoutService, useValue: { isCoarse: signal(coarse) } },
      ],
    });
    fixture = TestBed.createComponent(ShareButtonComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a?utm_source=rss&id=1');
    fixture.componentRef.setInput('title', 'First');
    fixture.detectChanges();
  }

  beforeEach(() => {
    toast.show.mockReset();
    sheet.open.mockReset();
  });

  afterEach(() => fixture.destroy());

  function press(): void {
    (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('button')!.click();
    fixture.detectChanges();
  }

  function openMenu(): HTMLElement {
    press();
    return document.querySelector<HTMLElement>('[role="menu"]')!;
  }

  function item(menu: HTMLElement, label: string): HTMLButtonElement {
    return Array.from(menu.querySelectorAll<HTMLButtonElement>('[role="menuitem"]')).find(
      (button) => button.textContent?.trim().endsWith(label),
    )!;
  }

  describe('on a fine pointer', () => {
    beforeEach(() => mount(false));

    it('lists the configured targets with their dividers and logos', () => {
      const menu = openMenu();

      expect(item(menu, 'Copy link')).toBeTruthy();
      expect(item(menu, 'Bluesky').querySelector('app-masked-logo')).not.toBeNull();
      expect(menu.querySelectorAll('hr').length).toBeGreaterThan(0);
      expect(sheet.open).not.toHaveBeenCalled();
    });

    it('opens a target with the article, tracking parameters removed', () => {
      const open = jest.spyOn(window, 'open').mockReturnValue(null);

      item(openMenu(), 'Bluesky').click();
      fixture.detectChanges();

      expect(open).toHaveBeenCalledWith(
        'https://bsky.app/intent/compose?text=First%20https%3A%2F%2Fexample.com%2Fa%3Fid%3D1',
        '_blank',
        'noopener,noreferrer',
      );
      expect(document.querySelector('[role="menu"]')).toBeNull();
    });

    it('copies the link and confirms it', async () => {
      const writeText = jest.fn().mockResolvedValue(undefined);
      Object.assign(navigator, { clipboard: { writeText } });

      item(openMenu(), 'Copy link').click();
      await Promise.resolve();

      expect(writeText).toHaveBeenCalledWith('https://example.com/a?id=1');
      expect(toast.show).toHaveBeenCalledWith(expect.objectContaining({ message: 'Link copied' }));
    });
  });

  describe('on a coarse pointer', () => {
    beforeEach(() => mount(true));

    function sheetData(): ActionSheetData {
      return sheet.open.mock.calls[0][0] as ActionSheetData;
    }

    function action(label: string): ActionSheetAction {
      return sheetData().actions.find(
        (entry: ActionSheetItem): entry is ActionSheetAction =>
          'label' in entry && entry.label === label,
      )!;
    }

    it('opens the action sheet with the same entries instead of the popover', () => {
      press();

      expect(document.querySelector('[role="menu"]')).toBeNull();
      expect(sheetData().title).toBe('Share');
      expect(action('Copy link')).toEqual({ id: expect.any(String), label: 'Copy link', symbol: 'link' });
      expect(action('Bluesky').logo).toEqual({ url: 'share-targets/bluesky.svg', color: '#1185fe' });
      expect(sheetData().actions.some((entry) => 'divider' in entry)).toBe(true);
    });

    it('fires the chosen target', () => {
      const open = jest.spyOn(window, 'open').mockReturnValue(null);
      press();

      chosen.next(action('Bluesky').id);

      expect(open).toHaveBeenCalledWith(
        'https://bsky.app/intent/compose?text=First%20https%3A%2F%2Fexample.com%2Fa%3Fid%3D1',
        '_blank',
        'noopener,noreferrer',
      );
    });

    it('does nothing when the sheet is dismissed', () => {
      const open = jest.spyOn(window, 'open').mockReturnValue(null);
      press();

      chosen.next(undefined);

      expect(open).not.toHaveBeenCalled();
    });
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/share/share-button`
Expected: the coarse cases FAIL — the sheet is never opened, the popover renders.

- [ ] **Step 3: Implement**

`share-targets.ts`, after `SHARE_GLYPH`:

```ts
/** The logo files sit next to the config, under the app's public root. */
export function shareLogoUrl(file: string): string {
  return `share-targets/${file}`;
}
```

`share-button.component.ts`: import `DestroyRef`, `takeUntilDestroyed`, `LayoutService`, `ActionSheet`, `ACTION_SHEET_DIVIDER`, `ActionSheetItem`, `MaskedLogoComponent`, `shareLogoUrl`; add `MaskedLogoComponent` to `imports`, drop nothing. New members:

```ts
  private readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly logoUrl = shareLogoUrl;

  /** Coarse pointers get the bottom sheet the sidebar rows use; a popover has nothing to anchor to on a phone. */
  protected press(): void {
    if (this.screen.isCoarse()) this.openSheet();
    else this.open.update((open) => !open);
  }

  private openSheet(): void {
    this.sheet
      .open({
        title: this.i18n.translate('reader.share.button'),
        actions: this.targets.map((target, index) => this.sheetItem(target, index)),
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => this.chooseByIndex(choice));
  }

  private sheetItem(target: ShareTarget, index: number): ActionSheetItem {
    if (target.kind === 'divider') return ACTION_SHEET_DIVIDER;
    return {
      id: String(index),
      label: target.labelKey ? this.i18n.translate(target.labelKey) : (target.label ?? ''),
      symbol: target.icon ? undefined : (target.symbol ?? this.glyph),
      logo: target.icon ? { url: shareLogoUrl(target.icon), color: target.color } : undefined,
    };
  }

  private chooseByIndex(choice: string | undefined): void {
    if (choice === undefined) return;
    const target = this.targets[Number(choice)];
    if (target.kind !== 'divider') this.choose(target);
  }
```

`share-button.component.html`: the trigger's `(click)="open.set(!open())"` becomes `(click)="press()"`; the `.logo` span becomes

```html
          @if (target.icon) {
            <app-masked-logo [src]="logoUrl(target.icon)" [color]="target.color" />
          } @else {
```

`share-button.component.scss`: delete the `.logo` rule.

- [ ] **Step 4: Run the specs to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/share src/app/shared/action-sheet src/app/shared/masked-logo`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/article/share
git commit -m "fix(#1716): the share button opens the action sheet on a coarse pointer"
```

---

### Task 4: Gate, browser check, PR

- [ ] **Step 1: The frontend gate**

Run: `cd frontend && npm run check`
Expected: ESLint, Prettier, Stylelint, tsc and Jest all green.

- [ ] **Step 2: Browser check**

With the Docker stack up, open `http://localhost:4200` in the built-in browser at the Mobile preset (it emulates `pointer: coarse`): the Share button opens the bottom sheet, with the logos, the dividers and the "Share" title; a tap on Copy link shows the toast; swipe down and Escape dismiss. At the desktop preset the popover is unchanged, logos included.

- [ ] **Step 3: PR**

Push `fix/1716-share-menu-sheet` and open a PR against `develop` whose body starts with `Closes #1716`, names the cause (one surface in the share button, the coarse branch from #1699 cut in #1709) and the change. Do not merge.
