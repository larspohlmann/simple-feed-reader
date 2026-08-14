# AI Settings Page Documentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/settings/ai` self-explaining: an info affordance on every control and a collapsed step-by-step setup guide at the top of the page, on desktop and mobile.

**Architecture:** A new shared `<app-info-tip>` (icon button toggling an in-flow explanation panel — no floating overlay, so nothing can clip or overflow on a phone), an `info` input on `<app-field>` that renders the tip in the field's label row, and a collapsible `app-settings-card` reused as the guide. Frontend only; no behaviour of any control changes.

**Tech Stack:** Angular 20 standalone components with signals, Transloco i18n, Jest, Stylelint/ESLint/Prettier via `npm run check`.

Spec: `docs/superpowers/specs/2026-08-14-ai-settings-page-docs-design.md`
Issue: [#372](https://github.com/larspohlmann/simple-feed-reader/issues/372)
Branch: `feature/372-ai-settings-page-docs` off `develop` — **create it first; the spec and this plan are its first commit** (they were authored outside the repo while the checkout was busy on #371).

## Global Constraints

- **Shared checkout:** other Claude sessions may be mid-edit. Check `git status` before any `checkout`; never `reset` or `stash` another session's work.
- All new copy goes through Transloco: keys under `settings.ai.info.*` and `settings.ai.guide.*`, in **both** `frontend/public/i18n/en.json` and `de.json`. German uses the informal "du" tone of the existing file.
- Shared components (`src/app/shared/`) take **already-translated strings**, never i18n keys.
- No hex colours, no raw `px` for padding/margin/gap/font-size/border-radius in any `.scss` outside `theme/` — tokens only (`--space-*`, `--fs-sm`, `--radius`, `--border`, `--surface-2`, `--text-muted`, `--text-primary`, `--tap-target`). `ch` units are allowed for widths.
- Component styles live in a sibling `.scss` (`styleUrl`), never inline.
- Touch sizing keys on `@media (pointer: coarse)` with `--tap-target`, never on viewport width.
- No info tip inside a `<summary>` or wrapping `<label>` element (click-forwarding and content-model traps — see spec).
- Standalone components, `ChangeDetectionStrategy.OnPush`, signal `input()`s.
- Frontend gate: `cd frontend && npm run check` (ESLint + Prettier + Stylelint + Jest). Prettier also checks the i18n JSON — keep it formatted.
- No backend changes. No new e2e specs.

---

### Task 0: Branch and documents

**Files:**
- Create: `docs/superpowers/specs/2026-08-14-ai-settings-page-docs-design.md` (content delivered alongside this plan)
- Create: `docs/superpowers/plans/2026-08-14-372-ai-settings-page-docs.md` (this file)

- [ ] **Step 1: Create the branch**

```bash
git status   # confirm no other session is mid-edit on tracked files you need
git checkout develop && git pull && git checkout -b feature/372-ai-settings-page-docs
```

- [ ] **Step 2: Add the two documents and commit**

Copy the spec and this plan to the paths above.

```bash
git add docs/superpowers && git commit -m "docs(#372): spec and plan for the AI settings page documentation"
```

---

### Task 1: `<app-info-tip>` shared component

The one reusable info affordance: an ⓘ button that toggles an in-flow panel. Click/tap to toggle, Escape or an outside press to dismiss (via the existing `DismissOnOutsideDirective`), full keyboard and screen-reader wiring.

**Files:**
- Create: `frontend/src/app/shared/info-tip/info-tip.component.ts`
- Create: `frontend/src/app/shared/info-tip/info-tip.component.html`
- Create: `frontend/src/app/shared/info-tip/info-tip.component.scss`
- Test: `frontend/src/app/shared/info-tip/info-tip.component.spec.ts`
- Modify: `docs/design-language.md` (§2 component catalog, new entry after `<app-disclosure>`)

**Interfaces:**
- Consumes: `IconComponent` (`shared/icon/`), `DismissOnOutsideDirective` (`shared/dismiss-on-outside.directive.ts`).
- Produces: `InfoTipComponent` with selector `app-info-tip`; inputs `text: string` (required, translated panel text), `label: string` (required, translated accessible name of the trigger), `corner` (boolean attribute, default `false` — positions the trigger at the top-right of the nearest **positioned ancestor**); signal `open`; methods `toggle(event: Event): void`, `close(): void`. Tasks 2–5 rely on exactly these names.

- [ ] **Step 1: Write the failing spec**

```ts
// frontend/src/app/shared/info-tip/info-tip.component.spec.ts
import { Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { InfoTipComponent } from './info-tip.component';

@Component({
  imports: [InfoTipComponent],
  template: `<app-info-tip [text]="'The explanation.'" [label]="'Endpoint'" />`,
})
class HostComponent {}

describe('InfoTipComponent', () => {
  function mount(): ComponentFixture<HostComponent> {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return fixture;
  }

  const trigger = (fixture: ComponentFixture<HostComponent>): HTMLButtonElement =>
    fixture.nativeElement.querySelector('button.trigger') as HTMLButtonElement;

  const panel = (fixture: ComponentFixture<HostComponent>): HTMLElement | null =>
    fixture.nativeElement.querySelector('.panel');

  it('renders closed: a labelled trigger, no panel', () => {
    const fixture = mount();

    expect(trigger(fixture).getAttribute('aria-label')).toBe('Endpoint');
    expect(trigger(fixture).getAttribute('aria-expanded')).toBe('false');
    expect(panel(fixture)).toBeNull();
  });

  it('opens on click and wires the panel to the trigger', () => {
    const fixture = mount();

    trigger(fixture).click();
    fixture.detectChanges();

    expect(trigger(fixture).getAttribute('aria-expanded')).toBe('true');
    expect(panel(fixture)).not.toBeNull();
    expect(panel(fixture)!.textContent).toContain('The explanation.');
    expect(panel(fixture)!.getAttribute('role')).toBe('note');
    expect(trigger(fixture).getAttribute('aria-controls')).toBe(panel(fixture)!.id);
  });

  it('closes on a second click', () => {
    const fixture = mount();

    trigger(fixture).click();
    fixture.detectChanges();
    trigger(fixture).click();
    fixture.detectChanges();

    expect(panel(fixture)).toBeNull();
  });

  it('closes on Escape', () => {
    const fixture = mount();

    trigger(fixture).click();
    fixture.detectChanges();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    fixture.detectChanges();

    expect(panel(fixture)).toBeNull();
  });

  it('closes on a pointerdown outside, not on one inside', () => {
    const fixture = mount();

    trigger(fixture).click();
    fixture.detectChanges();
    panel(fixture)!.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    fixture.detectChanges();
    expect(panel(fixture)).not.toBeNull();

    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    fixture.detectChanges();
    expect(panel(fixture)).toBeNull();
  });

  it('swallows the trigger click so a wrapping summary or label never activates', () => {
    const fixture = mount();
    const reached = jest.fn();
    document.body.addEventListener('click', reached);

    trigger(fixture).click();

    document.body.removeEventListener('click', reached);
    expect(reached).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd frontend && npx jest info-tip`
Expected: FAIL — `Cannot find module './info-tip.component'`.

- [ ] **Step 3: Implement the component**

```ts
// frontend/src/app/shared/info-tip/info-tip.component.ts
import {
  booleanAttribute,
  ChangeDetectionStrategy,
  Component,
  input,
  signal,
} from '@angular/core';
import { DismissOnOutsideDirective } from '../dismiss-on-outside.directive';
import { IconComponent } from '../icon/icon.component';

let nextId = 0;

/**
 * The one info affordance (#372): a small ⓘ button that toggles an
 * explanation panel. The panel renders in normal flow and pushes content
 * down rather than floating — a floating popover needs viewport-collision
 * handling on phones, while an in-flow panel cannot clip or overflow by
 * construction. Click-to-toggle, never hover: hover does not exist on touch.
 *
 * `text` and `label` take already-translated strings, not i18n keys — this
 * component lives in `shared/` and must not hardcode a feature's translation
 * keys. `label` names the trigger for assistive tech; callers pass the label
 * of the control the tip explains, and `aria-expanded` tells it apart from
 * the control itself.
 *
 * `corner` places the trigger at the top-right of the nearest *positioned*
 * ancestor — `<app-field>` sets `position: relative` on its host and uses
 * this to put the ⓘ in its label row while the panel stays in the field's
 * flow. The host itself must stay unpositioned in corner mode for that
 * anchoring to work, which is why the styles never give it `position`.
 */
@Component({
  selector: 'app-info-tip',
  imports: [DismissOnOutsideDirective, IconComponent],
  templateUrl: './info-tip.component.html',
  styleUrl: './info-tip.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InfoTipComponent {
  readonly text = input.required<string>();
  readonly label = input.required<string>();
  readonly corner = input(false, { transform: booleanAttribute });

  readonly open = signal(false);

  /** Ties the trigger to its panel; unique so several tips can coexist. */
  protected readonly panelId = `info-tip-panel-${nextId++}`;

  /**
   * preventDefault + stopPropagation so a tip placed near a `<summary>` or a
   * `<label>` can never trigger the container's own activation — a click
   * that falls through would collapse the row or toggle the control the tip
   * is explaining.
   */
  toggle(event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    this.open.update((value) => !value);
  }

  close(): void {
    this.open.set(false);
  }
}
```

```html
<!-- frontend/src/app/shared/info-tip/info-tip.component.html -->
<span class="wrap" [appDismissOnOutside]="open()" (dismiss)="close()">
  <button
    type="button"
    class="trigger"
    [class.is-open]="open()"
    [attr.aria-expanded]="open()"
    [attr.aria-controls]="panelId"
    [attr.aria-label]="label()"
    (click)="toggle($event)"
  >
    <app-icon name="info" size="text" />
  </button>
  @if (open()) {
    <span class="panel" [id]="panelId" role="note">{{ text() }}</span>
  }
</span>
```

```scss
// frontend/src/app/shared/info-tip/info-tip.component.scss

/* The host and .wrap are deliberately never `position`ed: in corner mode the
   trigger's `absolute` must resolve against the consumer's own positioned
   ancestor (app-field's host), which is what puts the ⓘ in the label row. */
:host {
  display: inline-block;
}

:host([corner]) {
  display: block;
}

.trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-1);
  border: none;
  background: none;
  color: var(--text-muted);
  cursor: pointer;
  vertical-align: middle;
}

.trigger:hover,
.trigger:focus-visible,
.trigger.is-open {
  color: var(--text-primary);
}

:host([corner]) .trigger {
  position: absolute;
  top: 0;
  right: 0;
}

.panel {
  display: block;
  margin-top: var(--space-1);
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-2);
  color: var(--text-primary);
  font-size: var(--fs-sm);
  max-width: 60ch;
}

/* Touch density: the trigger grows to a full tap target for coarse pointers
   only, the documented local pattern (design-language §3). */
@media (pointer: coarse) {
  .trigger {
    min-width: var(--tap-target);
    min-height: var(--tap-target);
    padding: 0;
  }
}
```

- [ ] **Step 4: Run the spec and watch it pass**

Run: `cd frontend && npx jest info-tip`
Expected: PASS (6 tests).

- [ ] **Step 5: Document the component in the design language**

In `docs/design-language.md`, §2 component catalog, add after the `<app-disclosure>` entry (keeping the `---` separators):

```markdown
### `<app-info-tip>`

The one info affordance (#372): a small ⓘ icon button that toggles an
explanation panel. The panel renders **in flow** and pushes content down —
deliberately not a floating popover, so it cannot clip or overflow on a
phone. Click/tap to toggle; Escape or a press outside dismisses (via
`DismissOnOutsideDirective`). The trigger is a real button with
`aria-expanded`/`aria-controls`; the panel is `role="note"`. Under
`pointer: coarse` the trigger grows to `--tap-target`.

| Input | Type | Default |
|---|---|---|
| `text` | `string` (required) | — |
| `label` | `string` (required) | — |
| `corner` | boolean attribute | `false` |

```html
<app-info-tip
  [text]="'settings.ai.info.rowActions' | transloco"
  [label]="'settings.ai.info.actionsLabel' | transloco"
/>
```

`text` and `label` take **already-translated strings** (shared component, no
feature keys). `label` is the accessible name of the trigger — pass the label
of the control being explained. `corner` anchors the trigger at the top-right
of the nearest **positioned** ancestor; `<app-field>` uses it to put the ⓘ in
its label row (see the `info` input there). Never place a tip inside a
`<summary>` or inside a wrapping `<label>`: a click on non-interactive panel
content would toggle the `<details>` or the control.

**Not for:** validation or state messages (that is `app-field`'s `error`/
`hint`), or anything that must be visible without interaction — a danger
zone keeps its always-visible note.
```

- [ ] **Step 6: Lint and commit**

Run: `cd frontend && npm run check`
Expected: PASS.

```bash
git add frontend/src/app/shared/info-tip docs/design-language.md
git commit -m "feat(#372): add the shared app-info-tip component"
```

---

### Task 2: `info` input on `<app-field>`

One line per call site for the ~15 labelled controls: the field renders the tip's trigger at the top-right of its label row and the panel between control and hint.

**Files:**
- Modify: `frontend/src/app/shared/field/field.component.ts`
- Modify: `frontend/src/app/shared/field/field.component.html`
- Modify: `frontend/src/app/shared/field/field.component.scss`
- Test: `frontend/src/app/shared/field/field.component.spec.ts`
- Modify: `docs/design-language.md` (§2, the `<app-field>` entry's input table)

**Interfaces:**
- Consumes: `InfoTipComponent` from Task 1 (`corner` mode).
- Produces: `FieldComponent.info = input<string | null>(null)` — an already-translated explanation; when set, the field renders `<app-info-tip corner [text]="…" [label]="label()" />`. Tasks 3 and 4 use exactly `[info]="'…' | transloco"`.

- [ ] **Step 1: Write the failing spec additions**

Add to `frontend/src/app/shared/field/field.component.spec.ts` (follow the file's existing host-component mount pattern; if it mounts `FieldComponent` directly with `setInput`, use that same mechanism):

```ts
  it('renders an info tip named after the field when info is set', () => {
    const fixture = mountField({ label: 'Endpoint', info: 'What this endpoint is for.' });

    const trigger = fixture.nativeElement.querySelector(
      'app-info-tip button.trigger',
    ) as HTMLButtonElement;
    expect(trigger).not.toBeNull();
    expect(trigger.getAttribute('aria-label')).toBe('Endpoint');

    trigger.click();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('app-info-tip .panel')?.textContent).toContain(
      'What this endpoint is for.',
    );
  });

  it('renders no info tip without info', () => {
    const fixture = mountField({ label: 'Endpoint' });

    expect(fixture.nativeElement.querySelector('app-info-tip')).toBeNull();
  });
```

Where `mountField` is whatever helper the spec already uses to create the component with inputs — extend it to pass `info` through `fixture.componentRef.setInput('info', …)` when given.

- [ ] **Step 2: Run it and watch it fail**

Run: `cd frontend && npx jest shared/field`
Expected: FAIL — no `app-info-tip` in the DOM (and/or `info` is not a known input).

- [ ] **Step 3: Implement**

In `field.component.ts`: add the import and the input, and register the component.

```ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { InfoTipComponent } from '../info-tip/info-tip.component';
```

```ts
@Component({
  selector: 'app-field',
  imports: [InfoTipComponent],
  templateUrl: './field.component.html',
  styleUrl: './field.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FieldComponent {
  readonly label = input.required<string>();
  readonly error = input<string | null>(null);
  readonly hint = input<string | null>(null);
  /** Already-translated explanation; renders an `<app-info-tip>` whose
   *  trigger sits at the top-right of the label row (#372). */
  readonly info = input<string | null>(null);
  readonly required = input(false);
}
```

In `field.component.html`, insert between `</label>` and the hint block:

```html
@if (info(); as text) {
  <app-info-tip corner [text]="text" [label]="label()" />
}
```

In `field.component.scss`, extend the existing `:host` block (the tip's
`corner` trigger anchors to the nearest positioned ancestor, which this makes
the field itself):

```scss
:host {
  position: relative;
  display: block;
  margin-bottom: var(--space-4);
}
```

- [ ] **Step 4: Run the spec and watch it pass**

Run: `cd frontend && npx jest shared/field && npx jest info-tip`
Expected: PASS.

- [ ] **Step 5: Update the catalog entry**

In `docs/design-language.md`, add a row to the `<app-field>` input table:

```markdown
| `info` | `string \| null` | `null` — an already-translated explanation; renders an `<app-info-tip>` in the label row (#372) |
```

- [ ] **Step 6: Lint and commit**

Run: `cd frontend && npm run check`
Expected: PASS.

```bash
git add frontend/src/app/shared/field docs/design-language.md
git commit -m "feat(#372): app-field renders an info tip in its label row"
```

---

### Task 3: Info tips on the configs list and the add form

Every Section A and B control from the issue. The two long always-visible hints (`reasoningHint`, `batchConcurrencyHint`) migrate into tips; the short `baseUrlHint`/`apiKeyHint` stay.

**Files:**
- Modify: `frontend/src/app/settings/ai-section.component.ts` (imports)
- Modify: `frontend/src/app/settings/ai-section.component.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/ai-section.component.spec.ts`

**Interfaces:**
- Consumes: `InfoTipComponent` (Task 1), `FieldComponent.info` (Task 2).
- Produces: i18n keys `settings.ai.info.actionsLabel`, `.rowActions`, `.reasoning`, `.batchConcurrency`, `.modelPicker`, `.name`, `.baseUrl`, `.apiKey` — Task 6's checklist re-verifies them.

- [ ] **Step 1: Write the failing spec additions**

In `ai-section.component.spec.ts`, following the file's existing mount pattern (the Transloco testing provider loads the real `en.json`, so assertions can use real copy):

```ts
  it('gives the add-form fields info tips and keeps the short hints', () => {
    const fixture = mountWithConfigs([]);

    const triggers = Array.from(
      fixture.nativeElement.querySelectorAll('.add-group app-info-tip button.trigger'),
    ) as HTMLButtonElement[];
    expect(triggers.map((el) => el.getAttribute('aria-label'))).toEqual([
      'Optional name',
      'Endpoint',
      'API key',
    ]);
  });

  it('explains the row actions with one tip and the reasoning toggle with its own', () => {
    const fixture = mountWithConfigs([CONFIG]);

    const body = fixture.nativeElement.querySelector('.config-body') as HTMLElement;
    const actionsTrigger = body.querySelector(
      '.acts-info button.trigger',
    ) as HTMLButtonElement;
    expect(actionsTrigger.getAttribute('aria-label')).toBe('What these buttons do');

    actionsTrigger.click();
    fixture.detectChanges();
    expect(body.querySelector('.acts-info .panel')?.textContent).toContain('active');

    const reasoningTrigger = body.querySelector(
      '.reasoning-toggle app-info-tip button.trigger',
    ) as HTMLButtonElement;
    expect(reasoningTrigger).not.toBeNull();
    expect(body.querySelector('.reasoning-toggle .hint')).toBeNull();
  });
```

Adapt `mountWithConfigs`/`CONFIG` to the helpers the spec file actually has (it already mounts the section with a stubbed config list for the rename/duplicate tests — reuse that; the add-form test needs `.add-group` visible, which sits in a collapsible card, so `querySelector` still finds it in the DOM).

- [ ] **Step 2: Run and watch it fail**

Run: `cd frontend && npx jest ai-section`
Expected: FAIL — no `app-info-tip` anywhere.

- [ ] **Step 3: Add the strings**

In `frontend/public/i18n/en.json`, inside `settings.ai`, add a new `info` object after `errors`:

```json
"info": {
  "actionsLabel": "What these buttons do",
  "rowActions": "“Use this one” makes this configuration the active one — the Active badge marks it, and AI features always use the active configuration. “Change model” fetches the model list from the endpoint so you can pick one. “Duplicate” copies the configuration together with its stored key — useful for trying a second model side by side. “Rename” changes the label shown in this list. “Delete” removes the endpoint, the stored key and the model; AI features stop if it was the active configuration.",
  "reasoning": "Asks the model to answer without a separate reasoning phase, which saves time and tokens. Leave it on for most providers. Turn it off only if the endpoint rejects the request (for example a direct OpenAI URL).",
  "batchConcurrency": "How many batch requests one run sends at the same time (1–4). A hosted provider usually copes with 3–4 and finishes a run faster; a local model server is often best at 1. Lower it if the provider rate-limits you.",
  "modelPicker": "The list is fetched live from this configuration's endpoint. Pick the model AI features should use — the configuration is ready once a model is saved.",
  "name": "A free label for this configuration, shown only in this list. Leave it empty to show the endpoint's host instead.",
  "baseUrl": "The root URL of an OpenAI-compatible API, including the version path — for example https://api.openai.com/v1 for OpenAI itself, or the address of a local server such as LM Studio. Any provider that speaks the OpenAI chat API works.",
  "apiKey": "The key is sent to the server once and stored encrypted; it is never shown again. Afterwards the list only shows its last characters so you can tell keys apart. Enter a new key to replace the stored one."
},
```

In `frontend/public/i18n/de.json`, the same object:

```json
"info": {
  "actionsLabel": "Was diese Buttons tun",
  "rowActions": "„Diesen verwenden“ macht diese Konfiguration zur aktiven — das Aktiv-Abzeichen markiert sie, und KI-Funktionen nutzen immer die aktive Konfiguration. „Modell ändern“ lädt die Modellliste vom Endpunkt, damit du eines auswählen kannst. „Duplizieren“ kopiert die Konfiguration samt gespeichertem Schlüssel — nützlich, um ein zweites Modell parallel auszuprobieren. „Umbenennen“ ändert den Namen in dieser Liste. „Löschen“ entfernt den Endpunkt, den gespeicherten Schlüssel und das Modell; KI-Funktionen stehen nicht mehr zur Verfügung, wenn es die aktive Konfiguration war.",
  "reasoning": "Bittet das Modell, ohne separate Denkphase zu antworten — das spart Zeit und Tokens. Für die meisten Anbieter aktiviert lassen. Nur ausschalten, wenn der Endpunkt die Anfrage ablehnt (zum Beispiel eine direkte OpenAI-URL).",
  "batchConcurrency": "Wie viele Batch-Anfragen ein Lauf gleichzeitig sendet (1–4). Ein gehosteter Anbieter verkraftet meist 3–4 und beendet den Lauf schneller; ein lokaler Modell-Server läuft oft am besten mit 1. Senke den Wert, wenn der Anbieter dich drosselt.",
  "modelPicker": "Die Liste wird live vom Endpunkt dieser Konfiguration geladen. Wähle das Modell, das die KI-Funktionen nutzen sollen — die Konfiguration ist einsatzbereit, sobald ein Modell gespeichert ist.",
  "name": "Ein freier Name für diese Konfiguration, nur in dieser Liste sichtbar. Leer lassen, um stattdessen den Host des Endpunkts anzuzeigen.",
  "baseUrl": "Die Basis-URL einer OpenAI-kompatiblen API, inklusive Versionspfad — zum Beispiel https://api.openai.com/v1 für OpenAI selbst oder die Adresse eines lokalen Servers wie LM Studio. Jeder Anbieter, der die OpenAI-Chat-API spricht, funktioniert.",
  "apiKey": "Der Schlüssel wird einmal gesendet und verschlüsselt gespeichert; er wird nie wieder angezeigt. Danach zeigt die Liste nur seine letzten Zeichen, damit du Schlüssel unterscheiden kannst. Gib einen neuen Schlüssel ein, um den gespeicherten zu ersetzen."
},
```

Remove nothing from the JSON yet — `reasoningHint` and `batchConcurrencyHint` become unused in Step 4; delete those two keys (both languages) once the template no longer references them.

- [ ] **Step 4: Edit the template**

In `ai-section.component.ts`, add `InfoTipComponent` to the imports array:

```ts
import { InfoTipComponent } from '../shared/info-tip/info-tip.component';
```

In `ai-section.component.html`:

**(a) Reasoning toggle** — replace the whole `<label class="reasoning-toggle">…</label>` block (the tip must not live inside the label, and the hint line goes away):

```html
<div class="reasoning-toggle">
  <span class="reasoning-toggle-row">
    <label class="reasoning-check">
      <input
        type="checkbox"
        [checked]="config.suppressReasoning"
        [disabled]="ai.busy()"
        (change)="toggleReasoning(config, $event)"
      />
      <span>{{ 'settings.ai.configs.reasoning' | transloco }}</span>
    </label>
    <app-info-tip
      [text]="'settings.ai.info.reasoning' | transloco"
      [label]="'settings.ai.configs.reasoning' | transloco"
    />
  </span>
</div>
```

Add to `ai-section.component.scss` (`.reasoning-toggle` and `.reasoning-toggle-row` already exist and keep working; the inner label needs the row layout the outer label had):

```scss
.reasoning-check {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  cursor: pointer;
}
```

**(b) Batch concurrency** — swap the hint for the tip on the existing field:

```html
<app-field
  [label]="'settings.ai.configs.batchConcurrency' | transloco"
  [info]="'settings.ai.info.batchConcurrency' | transloco"
>
```

**(c) Model picker** — the field inside `.model-picker`:

```html
<app-field
  [label]="'settings.ai.model' | transloco"
  [info]="'settings.ai.info.modelPicker' | transloco"
>
```

**(d) Row actions** — directly **after** the closing `</div>` of the `.acts` block inside `@if (renamingId() !== config.id)`:

```html
<app-info-tip
  class="acts-info"
  [text]="'settings.ai.info.rowActions' | transloco"
  [label]="'settings.ai.info.actionsLabel' | transloco"
/>
```

**(e) Add form** — the three fields in `.add-group` get `info`; the two short hints stay:

```html
<app-field
  [label]="'settings.ai.configs.namePlaceholder' | transloco"
  [info]="'settings.ai.info.name' | transloco"
>
```

```html
<app-field
  [label]="'settings.ai.baseUrl' | transloco"
  [hint]="'settings.ai.baseUrlHint' | transloco"
  [info]="'settings.ai.info.baseUrl' | transloco"
>
```

```html
<app-field
  [label]="'settings.ai.apiKey' | transloco"
  [hint]="'settings.ai.apiKeyHint' | transloco"
  [info]="'settings.ai.info.apiKey' | transloco"
>
```

Note the rename field inside the config body (`renamingId() === config.id`) keeps no tip — it is the same "optional name" concept and the rename flow is transient.

Now delete the `reasoningHint` and `batchConcurrencyHint` keys from `en.json` and `de.json`.

- [ ] **Step 5: Run the specs**

Run: `cd frontend && npx jest ai-section`
Expected: PASS, including the pre-existing tests (one of them may assert the old reasoning hint text — update it to the new structure if so).

- [ ] **Step 6: Lint and commit**

Run: `cd frontend && npm run check`
Expected: PASS.

```bash
git add frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#372): info tips on the AI configs list and the add form"
```

---

### Task 4: Info tips on the recommendation settings and debug toggle

Every Section C control: schedule, look-back, the expert fields, fixed prompt, debug toggle, danger zone.

**Files:**
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.ts` (imports)
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.html`
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/recommendation-settings-card.component.spec.ts`

**Interfaces:**
- Consumes: `InfoTipComponent` (Task 1), `FieldComponent.info` (Task 2), the `settings.ai.info` object created in Task 3.
- Produces: i18n keys `settings.ai.info.autoGenerate`, `.lookback`, `.guidance`, `.favoritesCap`, `.keptCap`, `.viewedCap`, `.candidatePool`, `.picksLimit`, `.batchCount`, `.contextWindow`, `.fixedPrompt`, `.debug`, `.purge`.

- [ ] **Step 1: Write the failing spec additions**

In `recommendation-settings-card.component.spec.ts`:

```ts
  it('gives every expert field an info tip', () => {
    const fixture = mount();

    const grid = fixture.nativeElement.querySelector('.expert-grid') as HTMLElement;
    const triggers = Array.from(
      grid.querySelectorAll('app-info-tip button.trigger'),
    ) as HTMLButtonElement[];
    expect(triggers.map((el) => el.getAttribute('aria-label'))).toEqual([
      'Favorites in history',
      'Kept in history',
      'Viewed in history',
      'Maximum articles',
      'Maximum picks',
      'Batches (empty = automatic)',
    ]);
  });

  it('explains the schedule, the look-back and the context window on their fields', () => {
    const fixture = mount();

    const labelled = (label: string): HTMLButtonElement | undefined =>
      (
        Array.from(
          fixture.nativeElement.querySelectorAll('app-field app-info-tip button.trigger'),
        ) as HTMLButtonElement[]
      ).find((el) => el.getAttribute('aria-label') === label);

    expect(labelled('Auto-generate For you')).toBeDefined();
    expect(labelled('Look back')).toBeDefined();
    expect(labelled('Context window (tokens)')).toBeDefined();
  });

  it('keeps the danger-zone note visible and adds a tip beside it', () => {
    const fixture = mount();

    const zone = fixture.nativeElement.querySelector('.danger-zone') as HTMLElement;
    expect(zone.querySelector('.danger-zone__note')?.textContent).toContain(
      'Removes every recommended post',
    );

    const trigger = zone.querySelector('app-info-tip button.trigger') as HTMLButtonElement;
    trigger.click();
    fixture.detectChanges();
    expect(zone.querySelector('app-info-tip .panel')?.textContent).toContain('cannot be undone');
  });

  it('adds a tip to the debug row without touching the toggle wiring', () => {
    const fixture = mount();

    const row = fixture.nativeElement.querySelector('.debug-row') as HTMLElement;
    expect(row.querySelector('app-info-tip button.trigger')).not.toBeNull();
    expect(row.querySelector('#rec-debug-toggle')).not.toBeNull();
  });
```

- [ ] **Step 2: Run and watch it fail**

Run: `cd frontend && npx jest recommendation-settings-card`
Expected: FAIL — no tips rendered.

- [ ] **Step 3: Add the strings**

Extend the `settings.ai.info` object from Task 3. `en.json`:

```json
  "autoGenerate": "How often a new “For you” run starts on its own. The schedule needs the background worker (Docker) or the maintenance cron — without one, nothing starts by itself and a note appears below with the call to trigger runs externally. “Only manually” disables the schedule; you can always start a run yourself.",
  "lookback": "How many days back a run looks for candidate articles. Only unread articles inside this window are considered; “Maximum articles” caps how many of them one run may take.",
  "guidance": "Your own steering text for the picks — topics to prefer, topics to avoid, tone. It is sent on top of the fixed prompt below. Leave it empty to use the built-in default (shown as the placeholder); “Reset to default” clears your text.",
  "favoritesCap": "How many of your most recently favorited articles are shown to the model as taste history. 0 leaves favorites out.",
  "keptCap": "How many of your most recently kept articles are shown to the model as taste history. 0 leaves kept articles out.",
  "viewedCap": "How many of your most recently viewed articles are shown to the model as taste history. 0 leaves viewed articles out.",
  "candidatePool": "The upper bound on unread articles one run considers, taken newest-first inside the look-back window. A larger pool sees more of your backlog but costs more tokens and time.",
  "picksLimit": "How many recommendations one run may produce at most.",
  "batchCount": "How the candidate pool is split into requests. Empty means automatic: the run packs as many articles per request as the context window allows. A fixed number forces exactly that many batches — useful when a provider struggles with large requests.",
  "contextWindow": "The token budget one request may use. The line below says where the current value comes from: your override here, the limit your provider reports, or the built-in default. Leave the field empty to use the provider value or the default.",
  "fixedPrompt": "The role and output contract every run sends. They are fixed so the response stays machine-readable — the guidance prompt above is the place for your own instructions.",
  "debug": "When on, every run keeps its full request and response log and recommendations show their scores. The log appears in the “Debug log” panel below once a run has produced entries.",
  "purge": "Deletes every recommended article from “For you” for this account. The articles themselves and their read state stay untouched. This cannot be undone — the next run builds a fresh list."
```

`de.json`:

```json
  "autoGenerate": "Wie oft ein neuer „Für dich“-Lauf von selbst startet. Der Zeitplan braucht den Hintergrund-Worker (Docker) oder den Wartungs-Cron — ohne beides startet nichts von selbst, und unten erscheint eine Notiz mit dem Aufruf, um Läufe extern auszulösen. „Nur manuell“ schaltet den Zeitplan ab; Läufe lassen sich weiterhin selbst starten.",
  "lookback": "Wie viele Tage ein Lauf zurückblickt, um Kandidaten-Artikel zu finden. Nur ungelesene Artikel in diesem Zeitraum kommen infrage; „Max. Artikel“ begrenzt, wie viele davon ein Lauf übernimmt.",
  "guidance": "Dein eigener Steuertext für die Auswahl — bevorzugte Themen, unerwünschte Themen, Tonalität. Er wird zusätzlich zum festen Prompt unten gesendet. Leer bedeutet der eingebaute Standard (als Platzhalter sichtbar); „Auf Standard zurücksetzen“ leert dein Feld.",
  "favoritesCap": "Wie viele deiner zuletzt favorisierten Artikel dem Modell als Geschmacks-Historie gezeigt werden. 0 lässt Favoriten weg.",
  "keptCap": "Wie viele deiner zuletzt aufbewahrten Artikel dem Modell als Geschmacks-Historie gezeigt werden. 0 lässt Aufbewahrte weg.",
  "viewedCap": "Wie viele deiner zuletzt angesehenen Artikel dem Modell als Geschmacks-Historie gezeigt werden. 0 lässt Angesehene weg.",
  "candidatePool": "Die Obergrenze an ungelesenen Artikeln, die ein Lauf betrachtet — die neuesten zuerst, innerhalb des Zeitraums. Ein größerer Pool sieht mehr von deinem Rückstand, kostet aber mehr Tokens und Zeit.",
  "picksLimit": "Wie viele Empfehlungen ein Lauf höchstens erzeugt.",
  "batchCount": "Wie der Kandidaten-Pool auf Anfragen verteilt wird. Leer heißt automatisch: Der Lauf packt so viele Artikel pro Anfrage, wie das Kontextfenster erlaubt. Eine feste Zahl erzwingt genau so viele Batches — nützlich, wenn ein Anbieter mit großen Anfragen kämpft.",
  "contextWindow": "Das Token-Budget einer einzelnen Anfrage. Die Zeile darunter zeigt, woher der aktuelle Wert stammt: deine Eingabe hier, das vom Anbieter gemeldete Limit oder der eingebaute Standard. Leer lassen, um Anbieterwert oder Standard zu nutzen.",
  "fixedPrompt": "Rolle und Ausgabe-Vertrag, die jeder Lauf sendet. Sie sind fest, damit die Antwort maschinenlesbar bleibt — für eigene Anweisungen ist der Leitprompt oben da.",
  "debug": "Wenn aktiv, behält jeder Lauf sein vollständiges Anfrage- und Antwortprotokoll, und Empfehlungen zeigen ihre Bewertung. Das Protokoll erscheint unten im Bereich „Debug-Protokoll“, sobald ein Lauf Einträge erzeugt hat.",
  "purge": "Entfernt alle empfohlenen Beiträge aus „Für dich“ für dieses Konto. Die Beiträge selbst und ihr Lesestatus bleiben unberührt. Das lässt sich nicht rückgängig machen — der nächste Lauf baut eine neue Liste auf."
```

- [ ] **Step 4: Edit the template**

In `recommendation-settings-card.component.ts`, add `InfoTipComponent` to the imports array:

```ts
import { InfoTipComponent } from '../shared/info-tip/info-tip.component';
```

In `recommendation-settings-card.component.html`:

**(a)** Auto-generate and look-back fields get `[info]`:

```html
<app-field
  [label]="'settings.ai.recommendations.autoGenerate' | transloco"
  [info]="'settings.ai.info.autoGenerate' | transloco"
>
```

```html
<app-field
  [label]="'settings.ai.recommendations.lookback' | transloco"
  [info]="'settings.ai.info.lookback' | transloco"
>
```

**(b)** Guidance field:

```html
<app-field
  [label]="'settings.ai.recommendations.guidance' | transloco"
  [info]="'settings.ai.info.guidance' | transloco"
>
```

**(c)** The six expert-grid fields, each with its key: `favoritesCap`, `keptCap`, `viewedCap`, `candidatePool` (on the "Maximum articles" field), `picksLimit`, `batchCount` — same one-line `[info]="'settings.ai.info.…' | transloco"` addition on each `<app-field>`.

**(d)** Context window keeps its dynamic source `hint` and gains the tip:

```html
<app-field
  [label]="'settings.ai.recommendations.contextWindow' | transloco"
  [hint]="contextWindowSourceKey() | transloco"
  [info]="'settings.ai.info.contextWindow' | transloco"
>
```

**(e)** Fixed prompt — the tip sits **after** the inner disclosure (never inside its summary):

```html
<app-disclosure [label]="'settings.ai.recommendations.fixedShow' | transloco">
  <pre class="fixed"
    >{{ state.fixedPrompt.role }}

{{ state.fixedPrompt.outputContract }}</pre>
</app-disclosure>
<app-info-tip
  class="fixed-info"
  [text]="'settings.ai.info.fixedPrompt' | transloco"
  [label]="'settings.ai.recommendations.fixedShow' | transloco"
/>
```

**(f)** Debug row — the tip joins the label inside `.debug-text` (the label is a `for`/`id` pair, not a wrapping label, so a sibling tip is safe):

```html
<span class="debug-text">
  <span class="debug-title">
    <label class="debug-label" for="rec-debug-toggle">{{
      'settings.ai.recommendations.debug' | transloco
    }}</label>
    <app-info-tip
      [text]="'settings.ai.info.debug' | transloco"
      [label]="'settings.ai.recommendations.debug' | transloco"
    />
  </span>
  <span class="debug-hint">{{ 'settings.ai.recommendations.debugHint' | transloco }}</span>
</span>
```

**(g)** Danger zone — the note stays; the tip follows it:

```html
<div class="danger-zone">
  <p class="danger-zone__note">
    {{ 'settings.ai.recommendations.purgeExplain' | transloco }}
    <app-info-tip
      [text]="'settings.ai.info.purge' | transloco"
      [label]="'settings.ai.recommendations.purge' | transloco"
    />
  </p>
  …
```

In `recommendation-settings-card.component.scss`, add:

```scss
.debug-title {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

.fixed-info {
  display: block;
  margin-bottom: var(--space-3);
}
```

- [ ] **Step 5: Run the specs**

Run: `cd frontend && npx jest recommendation-settings-card && npx jest ai-section`
Expected: PASS (fix any pre-existing assertion that counted `.debug-text` children).

- [ ] **Step 6: Lint and commit**

Run: `cd frontend && npm run check`
Expected: PASS.

```bash
git add frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#372): info tips across the recommendation settings"
```

---

### Task 5: The collapsed step-by-step guide

One collapsible `app-settings-card` as the first card on the page — the shared `<details>` wrapper makes it collapsed by default with no new accordion code.

**Files:**
- Modify: `frontend/src/app/settings/ai-section.component.html` (top of file)
- Modify: `frontend/src/app/settings/ai-section.component.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/ai-section.component.spec.ts`

**Interfaces:**
- Consumes: `SettingsCardComponent` `collapsible` mode (already imported in the section).
- Produces: i18n keys `settings.ai.guide.*`.

- [ ] **Step 1: Write the failing spec additions**

```ts
  it('renders the setup guide as the first card, collapsed by default', () => {
    const fixture = mountWithConfigs([]);

    const firstCard = fixture.nativeElement.querySelector('app-settings-card') as HTMLElement;
    expect(firstCard.querySelector('h2')?.textContent).toContain('Step-by-step setup');

    const details = firstCard.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);

    const steps = firstCard.querySelectorAll('.guide ol li');
    expect(steps.length).toBe(9);
  });
```

- [ ] **Step 2: Run and watch it fail**

Run: `cd frontend && npx jest ai-section`
Expected: FAIL — the first card is the configs list.

- [ ] **Step 3: Add the strings**

`en.json`, inside `settings.ai`, a `guide` object after `info`:

```json
"guide": {
  "title": "Step-by-step setup",
  "intro": "Two short walkthroughs: connect an AI endpoint first, then tune the “For you” recommendations.",
  "connectionTitle": "Configure the AI connection",
  "connectionStep1": "Open “Add a configuration” below.",
  "connectionStep2": "Enter the endpoint of an OpenAI-compatible API — for example https://api.openai.com/v1.",
  "connectionStep3": "Enter the API key for that endpoint.",
  "connectionStep4": "Press “Add configuration”, then fetch the model list with “Change model” and save a model.",
  "connectionStep5": "Press “Use this one” to make the configuration active.",
  "recommendationsTitle": "Configure the recommendations",
  "recommendationsStep1": "Check the active configuration: it shows a model, and the “Recommendations” card below says AI features are available.",
  "recommendationsStep2": "Pick an auto-generate schedule, or keep “Only manually”. The schedule needs the background worker or the maintenance cron.",
  "recommendationsStep3": "Optionally open “Expert settings” and adjust the history caps, the article and pick limits, and the guidance prompt.",
  "recommendationsStep4": "Save. Turn on Debug mode to inspect the next run in the debug log."
},
```

`de.json`:

```json
"guide": {
  "title": "Schritt-für-Schritt-Einrichtung",
  "intro": "Zwei kurze Anleitungen: zuerst einen KI-Endpunkt verbinden, dann die „Für dich“-Empfehlungen einstellen.",
  "connectionTitle": "KI-Verbindung einrichten",
  "connectionStep1": "Öffne unten „Konfiguration hinzufügen“.",
  "connectionStep2": "Trage den Endpunkt einer OpenAI-kompatiblen API ein — zum Beispiel https://api.openai.com/v1.",
  "connectionStep3": "Trage den API-Schlüssel für diesen Endpunkt ein.",
  "connectionStep4": "Drücke „Konfiguration hinzufügen“, lade dann mit „Modell ändern“ die Modellliste und speichere ein Modell.",
  "connectionStep5": "Drücke „Diesen verwenden“, um die Konfiguration zu aktivieren.",
  "recommendationsTitle": "Empfehlungen einstellen",
  "recommendationsStep1": "Prüfe die aktive Konfiguration: Sie zeigt ein Modell, und die Karte „Empfehlungen“ unten meldet, dass KI-Funktionen verfügbar sind.",
  "recommendationsStep2": "Wähle einen Zeitplan für „Für dich“ oder belasse es bei „Nur manuell“. Der Zeitplan braucht den Hintergrund-Worker oder den Wartungs-Cron.",
  "recommendationsStep3": "Öffne bei Bedarf die „Experteneinstellungen“ und passe Historien-Limits, Artikel- und Auswahl-Grenzen sowie den Leitprompt an.",
  "recommendationsStep4": "Speichern. Schalte den Debug-Modus ein, um den nächsten Lauf im Debug-Protokoll zu untersuchen."
},
```

- [ ] **Step 4: Add the card**

At the very top of `ai-section.component.html`, before the configs card (`app-settings-card` renders its heading as `h2`, so the two walkthrough headings are `h3`):

```html
<app-settings-card [heading]="'settings.ai.guide.title' | transloco" [collapsible]="true">
  <div class="guide">
    <p class="guide-intro">{{ 'settings.ai.guide.intro' | transloco }}</p>

    <h3>{{ 'settings.ai.guide.connectionTitle' | transloco }}</h3>
    <ol>
      <li>{{ 'settings.ai.guide.connectionStep1' | transloco }}</li>
      <li>{{ 'settings.ai.guide.connectionStep2' | transloco }}</li>
      <li>{{ 'settings.ai.guide.connectionStep3' | transloco }}</li>
      <li>{{ 'settings.ai.guide.connectionStep4' | transloco }}</li>
      <li>{{ 'settings.ai.guide.connectionStep5' | transloco }}</li>
    </ol>

    <h3>{{ 'settings.ai.guide.recommendationsTitle' | transloco }}</h3>
    <ol>
      <li>{{ 'settings.ai.guide.recommendationsStep1' | transloco }}</li>
      <li>{{ 'settings.ai.guide.recommendationsStep2' | transloco }}</li>
      <li>{{ 'settings.ai.guide.recommendationsStep3' | transloco }}</li>
      <li>{{ 'settings.ai.guide.recommendationsStep4' | transloco }}</li>
    </ol>
  </div>
</app-settings-card>
```

In `ai-section.component.scss`:

```scss
.guide-intro {
  margin: 0 0 var(--space-3);
  color: var(--text-muted);
  font-size: var(--fs-sm);
}

.guide h3 {
  margin: var(--space-3) 0 var(--space-1);
  font-size: 1em;
}

.guide ol {
  margin: 0;
  padding-left: var(--space-4);
}

.guide li {
  margin-bottom: var(--space-1);
}
```

- [ ] **Step 5: Run the specs**

Run: `cd frontend && npx jest ai-section`
Expected: PASS (a pre-existing test that queried the *first* `app-settings-card` for the configs list now needs the second — adjust selectors, not behaviour).

- [ ] **Step 6: Lint and commit**

Run: `cd frontend && npm run check`
Expected: PASS.

```bash
git add frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#372): collapsed step-by-step setup guide on the AI page"
```

---

### Task 6: Visual verification on desktop and mobile, then the PR

The gates prove the wiring; this task proves it **looks good and works well on desktop and mobile** — the explicit requirement — on the real render, both themes (memory: never diagnose or sign off visual work on a synthetic check).

**Files:** none changed unless a check finds something.

- [ ] **Step 1: Full frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS.

- [ ] **Step 2: Bring the stack up and open the page**

```bash
docker compose up -d
```

Open `https://localhost:8443` (or `npm start` against it), log in, go to Settings → AI.

- [ ] **Step 3: Desktop pass (≈1280px), light and dark**

Checklist — screenshot each state:
- The guide card is first and collapsed; opening it shows intro + two ordered lists; nothing overflows the card.
- Every `app-field` ⓘ sits on the label row's right edge, vertically aligned with the label text, and does not collide with long labels.
- Opening a tip pushes content down; no tip overlaps neighbouring controls; the panel reads comfortably (≤ 60ch).
- Expert grid (two columns): corner triggers land inside each grid cell, top-right of each field.
- Config row: the actions tip sits under the buttons row; opening it does not close the row's `<details>`.
- Keyboard: Tab reaches every trigger in DOM order, Enter toggles, Escape closes, focus stays on the trigger after close.
- Both themes: panel background/border legible, icon visible.

- [ ] **Step 4: Mobile pass (≈375px viewport, coarse pointer emulation), light and dark**

- Triggers hit `--tap-target` (44px) — tap them with touch emulation on.
- Panels span the card width, no horizontal scrolling anywhere on the page.
- The expert grid is single-column (`bp.$bp-sm`); triggers still top-right per field.
- The guide is readable; `<details>` toggles by tap.
- Tapping outside an open panel closes it without also activating what was tapped underneath (the dismiss is `pointerdown`-based; verify a stray tap on a button below only closes the panel on the first tap — if the button also fires, note it and move the dismiss discussion to review rather than hacking here).
- [ ] **Step 5: Accessibility spot check**

With VoiceOver (or the browser accessibility tree): a trigger announces as "«label», button, collapsed/expanded"; the panel text is reachable after the trigger; `role="note"` present.

- [ ] **Step 6: Fix, re-run, and finish the branch**

Fix anything the passes above surfaced (token-only styles; re-run `npm run check`), commit as `fix(#372): …`.

- [ ] **Step 7: Open the pull request**

```bash
git push -u origin feature/372-ai-settings-page-docs
gh pr create --base develop --title "feat(#372): in-page documentation for the AI settings" --body "Closes #372

Info tips on every control (new shared app-info-tip + app-field info input) and a collapsed step-by-step setup guide. Frontend only."
```

The body must say `Closes #372` so the merge into `develop` closes the issue. Do not merge and do not tag a deploy without an explicit go-ahead.

---

## Self-Review

**Spec coverage:** shared component → Task 1; field integration → Task 2; Section A + B controls (active/actions cluster, reasoning, concurrency, model picker, name, base URL, API key) → Task 3; Section C (schedule, look-back, guidance, six expert fields, context window, fixed prompt, debug, purge) → Task 4; guide collapsed by default → Task 5; mobile/desktop/theme/a11y acceptance → Task 6; design-language catalog duty → Tasks 1–2. Spec's out-of-scope items appear in no task.

**Placeholder scan:** every step carries actual code, copy, or a concrete command; the only "adapt to the file's helper" notes concern existing spec-file mount helpers whose exact shape the implementer sees in front of them, with the mechanism (`setInput`) named.

**Type consistency:** `InfoTipComponent` inputs `text`/`label`/`corner` are used with exactly those names in Tasks 2–5; `FieldComponent.info` is the name in Task 2's definition and every Task 3/4 call site; `toggle(event: Event)` matches the template's `toggle($event)`; the key names in Task 3/4/5 JSON blocks match every `| transloco` reference in the templates.

**Known traps this plan defuses:** a tip inside a `<summary>` collapses the row on tap (spec decision — none are placed there); a button inside a wrapping `<label>` violates the label content model (reasoning toggle restructured); Stylelint rejects raw px and hex (all styles token-based, `ch` for the panel measure); Prettier gates the i18n JSON; `provideTranslocoTesting()` loads the real `en.json`, so copy assertions in specs are live and will catch a renamed key.
