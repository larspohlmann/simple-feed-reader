# Design language

One vocabulary for the SPA: a token set, a small catalog of shared components, and
the conventions that keep new surfaces consistent with the ones already shipped.
Introduced by #126, which found the same list row implemented four different ways
and seven breakpoints where three were meant.

Everything here is enforced mechanically where it can be (Stylelint, via
`npm run check`) and written down where it cannot be. If a rule below looks
arbitrary, it is almost certainly recorded in [§6 Deliberate exceptions](#6-deliberate-exceptions)
or in a comment at the definition site — read that before changing it.

---

## 1. Tokens

All tokens are CSS custom properties declared in
`frontend/src/app/theme/tokens.scss`. Colour tokens are mode-dependent and come
from a theme mixin (`theme/themes/_graphite.scss`); everything below is
mode-invariant.

### Spacing

`--space-0` is a real half-step for tight gaps, not a stray. Anything off this
scale fails Stylelint outside `theme/` and `styles/`.

| Token | Value | For |
|---|---|---|
| `--space-0` | `2px` | hairline gaps — rail row gaps, icon-grid padding |
| `--space-1` | `4px` | label-to-control, icon-to-label |
| `--space-2` | `8px` | compact row padding-y, footer button gap |
| `--space-3` | `12px` | control padding-x, comfortable row padding-y |
| `--space-4` | `16px` | comfortable row padding-x, field bottom margin, `--bar-gap` |
| `--space-5` | `24px` | panel head/body/footer padding |
| `--space-6` | `32px` | section separation |
| `--space-7` | `48px` | page-level blocks |

### Radii

| Token | Value | For |
|---|---|---|
| `--radius-sm` | `4px` | an element nested inside an already-rounded container |
| `--radius` | `8px` | controls — buttons, inputs, selects, row hit areas |
| `--radius-lg` | `12px` | a large surface that carries content: card, dialog, panel |
| `--radius-pill` | `999px` | chips |

The `lg` step is not invented. The magazine hero, the magazine source group, the
discover panel and the auth card each arrived at 12px independently.

### Structural sizing

| Token | Value | For |
|---|---|---|
| `--control-h` | `40px` | the height of a button, input or select |
| `--bar-h` | `56px` | **fallback** for the floating app bar's height |
| `--tap-target` | `44px` | the documented minimum touch target |
| `--list-bar-h` | `0px` | the entry list's own bar; overwritten on its host once measured |
| `--bar-gap` | `var(--space-4)` | breathing room between a floating bar and the content under it |

**`--bar-h` is a fallback, not the height.** `ReaderShellComponent` measures the
real app bar at runtime and writes it to `--app-bar-h`. Every consumer must read
it as:

```scss
top: calc(var(--app-bar-h, var(--bar-h)) + var(--list-bar-h) + var(--bar-gap));
```

`--list-bar-h` is declared at `:root` with a `0px` default rather than written as
a `var()` fallback at each use site, so the `calc()` above reads as arithmetic.

### Icon sizes

Four steps, because the same tag glyph is rendered from a 12px pill up to a 20px
sidebar lead; three steps would inflate the pill.

| Token | Value | Named size |
|---|---|---|
| `--icon-xs` | `12px` | `xs` |
| `--icon-sm` | `16px` | `sm` |
| `--icon-md` | `20px` | `md` (default) |
| `--icon-lg` | `24px` | `lg` |
| — | `0.85em` | `text` |

Never write these directly in a component if an `<app-icon>` / `<app-tag-glyph>`
`size` input will do: `ICON_SIZE_TOKEN` in `shared/icon/icon.component.ts` maps
the named size onto the token, so no consumer writes a px.

**`text` is not a step on the scale.** It means "match the text you sit in", and
it is the right choice for any icon inline in a line of copy — the open-in-new
after a link, the glyph inside a tag pill. Such an icon has to agree with the
surrounding type, not with the scale, and it must keep agreeing when that type
changes. Snapping these to the nearest step is what made both of them look
oversized during #126: the open-in-new went 14px → 16px next to 13px text, and
the pill glyph held at 12px while `--fs-xs` pulled the pill's own text to 11px.

It resolves to **`0.85em`, not `1em`**, and that is deliberate. A Material
Symbol fills its em box; the lowercase text beside it reaches barely half of it.
Matching the two font sizes numerically still leaves the glyph looking about
twice the weight of the letters, so matching what the eye sees means going
under. Note this makes inline icons smaller than they were before #126 — the
open-in-new was 14px on 13px text on `develop`, i.e. already above `1em`.

The rule: **an icon inside a run of text uses `text`; a standalone icon — a
button's glyph, a list row's lead, a rail item — uses a step on the scale.**

### Row density

Two named pairs. Every list, rail and picker row derives its padding from one of
them — that is what keeps the picker and the reader's lists from drifting apart
again.

| Token | Value | |
|---|---|---|
| `--row-pad-y` | `var(--space-2)` | **compact** |
| `--row-pad-x` | `var(--space-3)` | |
| `--row-pad-comfy-y` | `var(--space-3)` | **comfortable** |
| `--row-pad-comfy-x` | `var(--space-4)` | |

**Compact** — navigation rails, chip strips, row menus, sidebar entries:
surfaces where scanning many items at once matters more than breathing room.
In use by `discover/category-rail`, `discover/category-chips`,
`reader/sidebar`, `admin/admin-catalog`.

**Comfortable** — the primary content lists a reader actually reads, and the
settings/admin rows that carry a control per line. In use by
`reader/entry-row`, `settings/tags-section`, `admin/admin-users`. The reader's
entry list is the standard the picker was measured against in #126, so it is the
one that must never get tighter.

### Type

| Token | Value | For |
|---|---|---|
| `--font-sans` | `system-ui, -apple-system, 'Segoe UI', roboto, sans-serif` | the only family |
| `--fs-xs` | `11px` | badges, counts |
| `--fs-sm` | `13px` | secondary text, dense rows, `size="sm"` buttons |
| `--fs-base` | `15px` | UI chrome — the `body` size |
| `--fs-read` | `16px` | **article body** |
| `--fs-lg` | `18px` | panel and section headings |
| `--fs-xl` | `24px` | page titles |
| `--lh-tight` | `1.25` | headings |
| `--lh-normal` | `1.5` | body copy |

**`--fs-read` is deliberately a step above `--fs-base`.** Long-form reading wants
more than UI chrome does. Article headings size in `em` against it, so changing
this one value rescales the whole article.

**`overflow-wrap: anywhere` is set on `body` and inherited everywhere**
(`src/styles/_base.scss`). Feed text is arbitrary — a title can be an
80-character compound noun, a summary a base64 id — and at the CSS default such
a token may not break at all, so it paints straight out of its card and off the
screen (#292). Do not repeat the declaration in a component; two components had
already patched themselves that way and every other block had not, which is how
the bug survived. A surface that must hold one line says so with
`white-space: nowrap` (the magazine kicker line, the sidebar rows), and the
inherited rule is inert there.

### Breakpoints

Three steps only. The seven values that existed before (560/720/800/820/899/900/960)
were drift, not intent.

| Variable | Value | Meaning |
|---|---|---|
| `bp.$bp-sm` | `560px` | phone |
| `bp.$bp-md` | `720px` | small tablet, phone landscape |
| `bp.$bp-lg` | `900px` | desktop layout switch |

**These are SCSS variables, not custom properties, and that is not an oversight.**
`@media` cannot read custom properties: `@media (width <= var(--bp-md))` is not
an error, it simply never matches — the media query is silently dead and the
mobile layout never appears. So the breakpoints live in a SCSS partial and are
resolved at build time.

Usage — `@use` the partial with the relative path from the stylesheet:

```scss
@use '../../theme/breakpoints' as bp;

@media (width <= bp.$bp-md) {
  :host { display: none; }
}
```

Stylelint's `media-feature-name-unit-allowed-list: { "width": [] }` forbids any
unit inside a `width` media feature, so a literal `@media (width <= 720px)` is a
lint failure. The variable is the only way through.

**The reader drawer's 720px boundary is class-driven, not media-driven.**
`LayoutService.NARROW_QUERY` is its single declaration; the shell binds
`.is-narrow` from that signal and `reader-shell.component.scss` keys the drawer
rules to the class. Do not add a `bp.$bp-md` media block for the drawer — that
would restore the two-sources drift #185 removed. `bp.$bp-*` remains correct
for purely presentational media queries that have no TS twin.

### Utility classes

Global, non-token CSS declared in `frontend/src/app/theme/_utilities.scss`
(`@use`d once from `src/styles.scss`, so it applies everywhere) rather than in
any one component's stylesheet.

| Class | For |
|---|---|
| `.sr-only` | Visually hides an element while keeping it in the accessibility tree — for a label assistive tech must expose that has no visible slot of its own (e.g. the freshness label on the admin user-detail feed rows: `<span class="sr-only">Last refresh:</span>` before the visible date). `aria-label`/`title` on a plain element are not a substitute: ARIA forbids naming a `role=generic` node, so most screen readers ignore both attributes on a bare `<span>`/`<div>`. |

---

## 2. Component catalog

All live in `frontend/src/app/shared/` and are standalone, `OnPush`, and use
signal `input()`s. `app-entry-meta` and `app-entry-actions` are the exceptions:
they live in `frontend/src/app/reader/` instead, because they know `EntryDto`
and the reader's i18n keys, and neither sets `OnPush`.

### `<app-icon>`

A Material Symbol at a named size.

| Input | Type | Default |
|---|---|---|
| `name` | `string` (required) | — |
| `size` | `'text' \| 'xs' \| 'sm' \| 'md' \| 'lg'` | `'md'` |

```html
<app-icon name="delete" size="sm" />
```

The size lands on the host's `font-size`, not on an inner span, so the two
consumers with a genuinely fluid pixel box — `app-favicon` and `app-user-avatar`
— can override it with a template style binding (which outranks a host binding).

**Not for:** a tag's or category's glyph — that is `<app-tag-glyph>`, which also
owns the colour fallback and the square footprint.

### `<app-tag-glyph>`

The one way to render a tag or catalog category. With an icon it renders the
glyph tinted; without one it falls back to a colour dot, so an icon-less tag is
still identifiable at a glance.

| Input | Type | Default |
|---|---|---|
| `name` | `string \| null` | `null` |
| `color` | `string \| null` | `null` (→ `var(--text-muted)`) |
| `size` | `'text' \| 'xs' \| 'sm' \| 'md' \| 'lg'` | `'md'` |

```html
<app-tag-glyph [name]="node.tag.icon" [color]="node.tag.color" size="md" />
```

The host is a square of the named size whichever branch renders, because a dot is
far smaller than a glyph and lists mix the two freely. Owning the footprint here
is what lets every consumer drop the fixed-width slot it used to need to keep tag
names on one left edge. Callers highlighting a selected row pass the highlight
colour in `color` (`'currentColor'`, say) — both branches honour it.

**Not for:** a surface that wants the dot **and** the glyph side by side. That is
a different design; see [§6](#6-deliberate-exceptions) on `settings/tags-section`.

### `<app-field>`

Form field layout: label, optional required marker, the projected control, an
optional hint and an optional error.

| Input | Type | Default |
|---|---|---|
| `label` | `string` (required) | — |
| `error` | `string \| null` | `null` |
| `hint` | `string \| null` | `null` |
| `info` | `string \| null` | `null` — an already-translated explanation; renders an `<app-info-tip>` in the label row (#372) |
| `required` | `boolean` | `false` |

```html
<app-field [label]="'dialog.tagForm.name' | transloco">
  <input id="tag-name" formControlName="name" maxlength="100" cdkFocusInitial />
</app-field>
```

**Deliberately not a `ControlValueAccessor`.** The native control stays in the
consumer's template with its own `formControlName`, so `type`, `autocomplete`,
`inputmode` and the rest need no re-exposure as inputs. This component owns only
what was being retyped: the label, the rhythm and the error slot. The projected
control is styled globally by `styles/_controls.scss`, because
`ViewEncapsulation` does not reach projected content.

**Not for:** a dense data grid whose controls have no visible label. The stacked
label would triple the row height — see `admin-catalog` in
[§6](#6-deliberate-exceptions).

### `<app-color-field>`

Colour chooser: a row of presets, a native picker for anything else, and an
optional clear button for "no colour". The presets come from
`shared/icon-choices.ts`, which stays the single place the palette is defined.

| Input / output | Type | Default |
|---|---|---|
| `value` | `string \| null` | `null` |
| `valueChange` | `output<string \| null>` | — |
| `clearable` | `boolean` | `true` |

The clear button's label is projected as content.

```html
<app-color-field [value]="color()" (valueChange)="color.set($event)">
  {{ 'dialog.tagForm.none' | transloco }}
</app-color-field>
```

Not a `ControlValueAccessor` either: both consumers drive it from a signal, and
`value`/`valueChange` keeps it usable with either. Pass `[clearable]="false"`
where the value is mandatory (a catalog category always has a colour).

**Not for:** a bare `<input type="color">` that needs no presets — that is a
native control and takes the global styling in `styles/_controls.scss`.

### `<app-icon-picker>`

A selector over the curated Material Symbols in `shared/icon-choices.ts` — the
same set the reader's tag form and the admin catalog both offer, so a tag and a
category are picked from one palette.

| Input | Type | Default |
|---|---|---|
| `value` | `model<string>` (two-way) | `''` — the empty string is "no icon" |
| `color` | `string \| null` | `null` — tints the trigger glyph |
| `inline` | `boolean` (attribute) | `false` |

**Two framings of one grid, chosen with `inline`:**

- *popover* (default) — a compact trigger that sits between other controls in a
  dense row. Used by the admin catalog.
- *inline* — the grid takes a permanent place and selection costs one click.
  Used by the tag form, which has room to spare.

```html
<!-- popover -->
<app-icon-picker [(value)]="category.icon" [color]="category.color" />

<!-- inline -->
<app-icon-picker inline [value]="icon() ?? ''" (valueChange)="icon.set($event || null)" />
```

Escape dismisses an open popover and is swallowed, so it does not also reach the
CDK dialog listening on `body` and close the whole form. Inline mode never opens,
so Escape passes through untouched and still belongs to the dialog.

**Not for:** picking an arbitrary Material Symbol. The set is curated on purpose.

### `<app-overlay-panel>`

The frame every interrupt surface renders inside: a centred card, capped at 90dvh
with its body scrolling, at every width. Owns the heading, the scrolling body and
the footer row, so a dialog's own stylesheet carries only what is specific to it.

A dialog stays a card on a phone too — a short confirm has no business opening as
a mostly-empty full page. The one exception is a route-level picker that *is* the
page rather than a modal over it (discover): it passes `fillOnMobile` to keep the
full-screen phone layout.

| Input | Type | Default |
|---|---|---|
| `heading` | `string` (required) | — |
| `headingLevel` | `1 \| 2` | `2` |
| `fillOnMobile` | `boolean` | `false` |

Content slots: default content becomes the scrolling body; `[headerActions]`
lands beside the heading; `[footer]` lands in the footer row (which hides itself
when empty).

```html
<app-overlay-panel [heading]="data.title" cdkTrapFocus>
  <p class="msg">{{ data.message }}</p>

  <app-button footer (click)="ref.close(false)">{{ 'dialog.cancel' | transloco }}</app-button>
  <app-button footer focusInitial variant="danger" (click)="ref.close(true)">
    {{ data.confirmLabel }}
  </app-button>
</app-overlay-panel>
```

`heading`, not `title`: an input called `title` on a component host collides with
the native attribute and renders a stray browser tooltip over every dialog.

`headingLevel` is an outline decision, not a typographic one — both render at
`--fs-lg`. A dialog opens over a page that already has an `h1`, so `2` is right
for every one of them. A panel that *is* the page (discover, a route rather than
an overlay) passes `1`.

Width and max-height are per-consumer and come from `--panel-w` / `--panel-max-h`
set on the consumer's host — not from inputs, so they stay in the stylesheet with
the rest of the sizing. In use: 400px (confirm), 440px (edit subscription), 460px
(tag form, and the component default), 520px (add feed), 1040px (discover).

The add-feed dialog is the wide variant: `--panel-w: 520px`, sized so a preview
row's 88×66 thumbnail and four-line snippet sit comfortably. It sets
`fillOnMobile`, so on a phone it becomes the full screen rather than a 92vw card
— a card would squeeze the row's title to ~125px. This is the first non-default
`--panel-w`; keep new panels on the 460px default unless their content needs the
room, and record the exception here.

**Not for:** a non-modal popover or dropdown menu. The panel declares
`role="dialog" aria-modal="true"`.

### `<app-confirm-dialog>` (via `Dialog.open(ConfirmDialogComponent, { data })`)

The app's one confirmation prompt: a title, a message and a Cancel/confirm pair,
built on `<app-overlay-panel>`. Every destructive action that isn't a delete-tag-
from-a-row-menu case routes through this rather than growing its own dialog.

| `ConfirmData` field | Type | Default |
|---|---|---|
| `title` | `string` (required) | — |
| `message` | `string` (required) | — |
| `confirmLabel` | `string` (required) | — |
| `danger` | `boolean` | `false` — weights the confirm button `danger` instead of `primary` |
| `requireText` | `string` | `undefined` — see below |

```ts
const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
  data: { title, message, confirmLabel, danger: true, requireText: user.email },
  role: 'alertdialog',
  panelClass: 'app-dialog',
});
```

`requireText` gates the confirm button behind a typed match: the dialog renders a
text field under the message, and the button stays disabled until the field's
value equals `requireText` exactly (case-sensitive, no prefix match). Use it for
a deletion that takes content with it and cannot be undone — account deletion,
not a single tag. A click is cheap; typing the thing you are about to lose is
not. `focusInitial` moves from the confirm button to this field when it is
present, because a disabled button cannot hold initial focus — without the move,
the dialog opens with focus on nothing.

**Not for:** confirmations that leave the door open to undo, or where the cost of
a wrong click is low (reordering, unpinning). Those take a plain `ConfirmData`
with no `requireText` and keep the one-click confirm.

### `<app-button>`

The app's one ordinary button: a label, optionally a leading icon, one of five
weights.

| Input | Type | Default |
|---|---|---|
| `type` | `'button' \| 'submit'` | `'button'` |
| `variant` | `'default' \| 'primary' \| 'accent-outline' \| 'danger' \| 'danger-outline' \| 'ghost'` | `'default'` |
| `size` | `'sm' \| 'md'` | `'md'` |
| `loading` | `boolean` | `false` — swaps the label for a spinner and disables |
| `disabled` | `boolean` | `false` |
| `block` | `boolean` (attribute) | `false` — stretch to the container's width |
| `focusInitial` | `boolean` (attribute) | `false` |

| Variant | Weight | Use for |
|---|---|---|
| `default` | bordered, surface fill | the ordinary action |
| `primary` | accent fill | the one action the surface exists for |
| `accent-outline` | accent border, accent text | live, but not that one action — a secondary action on a card whose save bar already owns `primary` |
| `danger` | filled danger | **confirming** a destructive action |
| `danger-outline` | danger border, danger text | **initiating** a destructive action |
| `ghost` | no chrome at rest | the quiet way out — Cancel, Skip |

`size="sm"` is for dense rows (a settings list, an admin table) where a 40px
control would set the row height instead of the content; its height comes from
padding, so the label and any leading icon still decide it.

`focusInitial` puts `cdkFocusInitial` on the real `<button>`. The CDK's focus
trap calls `focus()` on the element carrying that attribute, and this component's
host is not focusable — put the attribute on the host and the dialog opens with
nothing focused.

`block` is opt-in. It used to be unconditional, which is why no surface outside
the auth forms could adopt this component.

#### Two rules that were decided the hard way

**1. `<app-button>` is for ordinary action buttons, and nothing else.** It is
*not* for icon-only affordances that carry their own interaction semantics.
These deliberately stay out and own their markup and styles:

- the sidebar's `.dots` row-menu triggers
- the entry row's read toggle
- the view-controls segmented control
- `<app-to-top-button>`
- `<app-icon-picker>`'s trigger

Forcing those through `<app-button>` would turn it into a grab bag: each needs a
different hit area, a different pressed/active state, or an `aria-*` contract
this component does not model.

**2. Destructive weight is a two-step scale.** Filled `danger` *confirms* a
destructive action — it is the moment of destruction and should carry the weight
of one. `danger-outline` only *initiates* one: the Delete sitting on every row of
a list, which opens a confirmation rather than destroying anything itself.
Flattening the two would make every Delete in a list shout as loudly as the
confirmation.

### `<app-error-banner>`

The app's one error banner: a message in a `role="alert"` region, and an
optional single action button — retry a failed load, dismiss a failed row
action, or neither for a plain inline failure message.

| Input / output | Type | Default |
|---|---|---|
| `message` | `string` (required) | — |
| `actionLabel` | `string \| null` | `null` — omits the button |
| `action` | `output<void>` | — |

```html
@if (error()) {
  <app-error-banner
    [message]="error()!.detail || error()!.title"
    [actionLabel]="'admin.retry' | transloco"
    (action)="load()"
  />
}
```

`actionLabel` takes an already-translated string, not an i18n key — the
component lives in `shared/` and must not hardcode a feature's translation
keys (`admin.retry` / `admin.dismiss` today). Extracted in #180 when the
markup and styles behind three admin screens' error banners had drifted into
byte-identical copies.

**Not for:** a toast or a non-blocking notification — this is a static block
that occupies layout where it renders, not an overlay.

---

### `<app-toast>` (via the `ToastService`)

The app's one toast: a surface pinned to the bottom of the viewport, with an
optional single action, auto-dismissing after `durationMs` (default 6000ms).
Rendered through the CDK overlay, never `position: fixed` — a transformed
ancestor (an open drawer, a dialog) would re-anchor a fixed child to the wrong
containing block (#85, #100). `hasBackdrop: false`, `autoFocus: false`,
`restoreFocus: false`: a toast must never steal focus from whatever the user
is doing, unlike every other surface in this catalog.

```ts
private readonly toast = inject(ToastService);

this.toast.show({
  message: this.transloco.translate('reader.recommendations.applied'),
  actionLabel: this.transloco.translate('reader.recommendations.undo'),
  action: () => this.undo(),
});
```

A toast carries **either** a `message` or a `content` component, never both.
`ToastData` is a union of the two, so the compiler rejects a toast that tries
to be both at once.

| `ToastData` field | Type | Default |
|---|---|---|
| `message` | `string` — the message mode | — |
| `content` | `Type<unknown>` — the content mode | — |
| `actionLabel` | `string` | `undefined` — omits the button |
| `action` | `() => void` | `undefined` — runs before the toast closes |
| `durationMs` | `number \| null` | `6000`; `null` never auto-dismisses |
| `width` | `'fit' \| 'fixed'` | `'fit'` — the pane sizes to its content |

`ToastService` also exposes `visible: Signal<boolean>` — whether a toast is on
screen at all. It is there for the persistent mode: a feature that raises a
long-lived toast needs to know the user closed it.

`message` and `actionLabel` are already-translated strings — the component
lives in `shared/` and must not know any feature's i18n keys. `content` is a
component built through `NgComponentOutlet`, so it injects and reads its own
feature's services and resolves its own translations; nothing is threaded
through `ToastData`. `show()` replaces whatever toast is already visible,
clearing its timer; there is no queue. There is only one toast, so
`ToastService` is injected directly rather than opened against a template
reference.

**The persistent modes.** `durationMs: null` and `width: 'fixed'` exist for one
caller, and adding a second is a design decision, not a convenience: the
For-You run pill (#398). A recommendation run takes minutes and the user
navigates away from it, so its progress readout is `ForYouProgressComponent`
hosted as `content` with no dismiss timer, and `RecommendationsService` puts the
ready message in the same slot when the run ends. `width: 'fixed'` pins the pane
to `22rem` across that handover — the pane is otherwise content-sized and
centre-anchored, so the box would shrink from both edges at the moment of
completion. Whoever raises a persistent toast owns taking it down: the run's
`finish()` is the single exit from every end state and calls `dismiss()` there.

A persistent toast must also be recoverable, because the ✕ is always available
and a minutes-long surface will get closed. `ToastService.visible` is a signal
for exactly that: the run derives `pillHidden` from `running() && !visible()`
and the reader's list header offers the pill back beside the Stop button.

**Accessibility.** The toast shell owns the `role="status" aria-live="polite"`
region. A `content` component must not declare a second one inside it, and must
hide any value that changes faster than the user can read it — the run pill's
ETA is `aria-hidden`, so only the batch count is announced.

**Not for:** a failure that blocks the surface it reports on — use
`<app-error-banner>` instead, which stays in the document until its own
action or a reload clears it. The message mode is for a transient, dismissible
confirmation of something that already happened (a background refresh
finished, a bulk action applied) that the user does not have to act on.

---

### `<app-settings-card>`

The one surface a settings or admin section sits in: a heading, an optional
description line, and the section's own projected content. A `cardActions`
slot puts a control (a "New tag" button, a filter) on the heading row.

| Input | Type | Default |
|---|---|---|
| `heading` | `string` (required) | — |
| `description` | `string \| null` | `null` — omits the line |

```html
<app-settings-card [heading]="'settings.tags.title' | transloco">
  <app-button cardActions size="sm" variant="primary" (click)="manage.createTag()">
    {{ 'settings.tags.new' | transloco }}
  </app-button>
  <ul class="list">
    …
  </ul>
</app-settings-card>
```

`heading` and `description` take already-translated strings, not i18n keys — the
component lives in `shared/` and must not hardcode a feature's translation keys.
Extracted in #180 Phase 4, when five card/panel treatments had accumulated
across seven stylesheets.

**`cardActions` content must be a direct child of `<app-settings-card>`.**
Angular's content projection only looks one `@if` level deep to find a
projectable node; wrap it in two (e.g. an outer `@else if (data(); as d)`
around an inner `@if (hasActions())`) and the block silently stops being
projected — it renders mid-body below the heading instead of beside it,
with no error anywhere. If the actions depend on data that only exists once
loaded, compute a single boolean (or resolve what you need from a signal
directly) so the `cardActions` element itself sits one level below
`<app-settings-card>`, not nested inside another control-flow block first.
This bit `admin-user-detail.component.html` once already — see its
`hasActions` computed for the shape.

**A card wraps a section, not a row.** Rows stay plain rows inside one card.
Giving each row its own border reads as nested cards — that is what the tags
list did before this component existed.

**Not for:** a dialog surface (use the CDK dialog with `panelClass: 'app-dialog'`)
or an overlay (`<app-overlay-panel>`).

---

### `<app-disclosure>`

The one wrapper for a native `<details>`/`<summary>` collapsed-content pattern:
a summary line and the projected body. No open/closed signal, no animation, no
ARIA reimplementation — `<details>` already gives all three for free.

| Input / output | Type | Default |
|---|---|---|
| `label` | `string` | `''` — an already-translated summary line |
| `appearance` | `'pill' \| 'row' \| 'card-header' \| 'drill-in'` | `'pill'` |
| `startOpen` | `boolean` | `false` — one-way; the caller's state decides the initial open state |
| `opened` | `output<void>` | — fires when the body is revealed, and only then |

```html
<app-disclosure [label]="'Show the fixed prompt' | transloco">
  <pre class="fixed">…</pre>
</app-disclosure>
```

`label` takes an already-translated string, not an i18n key — the component
lives in `shared/` and must not hardcode a feature's translation keys. A caller
that needs a richer summary projects its own markup into the `[summary]` slot
and leaves `label` unset. Extracted in #321 from the two places this shape had
been hand-rolled (`recommendation-settings-card`'s fixed-prompt panel,
`recommendation-debug-log`'s panel shell). It owns only the toggle line
(cursor, colour, size, full-width block); a host that needs panel-level
layout — margin, base font-size, the bordered entry rows — keeps that on its
own wrapping class, the same way `recommendation-debug-log.component.scss`'s
`.debug-panel` still does.

`appearance` picks the summary chrome. `pill` (default) is the bordered toggle
button. `row` is a flat, full-width list row for one disclosure per list item.
`card-header` is a flat, full-width heading with no horizontal padding, so it
aligns to a card's content box (`<app-settings-card>`'s collapsible mode).
`drill-in` (#541) is a full-width Grouped list row: the projected heading/label
sits on the left, a trailing chevron rotates when the `<details>` opens. It
reuses the same `startOpen`/`opened`/`label`/`[summary]` API. Use `drill-in`
for an advanced or Expert section that expands in place inside a settings
group.

---

### `<app-info-tip>`

The one info affordance (#372): a small ⓘ icon button that toggles an
explanation panel. The panel is a **floating popover** (#541): it is
`position: absolute`, anchored to the ⓘ trigger, right-aligned and
viewport-clamped (`max-width: min(20rem, calc(100vw - var(--space-4)))`), so it
never clips on a phone. Opening it does not shift the sibling layout. Click/tap
to toggle; Escape or a press outside dismisses (via `DismissOnOutsideDirective`).
Only one tip is open at a time — a module-level reference closes the previous
one when a new one opens. The trigger is a real button with
`aria-expanded`/`aria-controls`. Under `pointer: coarse` the trigger grows to
`--tap-target`.

`.wrap` is the positioning context: an inline anchor holding the trigger, with
the panel absolutely positioned against it. This supersedes the earlier in-flow
arrangement (#433/#372), where host and wrapper were `display: contents` and the
panel claimed a full-width line below the row; #541 clamps the popover in CSS
instead, so a settings row no longer grows a full-width block when a tip opens.

| Input | Type | Default |
|---|---|---|
| `text` | `string` (required) | — |
| `label` | `string` (required) | — |

```html
<app-info-tip
  [text]="'settings.ai.info.rowActions' | transloco"
  [label]="'settings.ai.info.actionsLabel' | transloco"
/>
```

`text` and `label` take **already-translated strings** (shared component, no
feature keys). `label` is the accessible name of the trigger — pass the label
of the control being explained.

A tip inside a `<summary>` or a wrapping `<label>` is fine — both the trigger
and the panel call `preventDefault` and `stopPropagation`, so no click of
theirs reaches the container to collapse the `<details>` or toggle the control.
`<app-field>` relies on this: its tip sits in the label row inside the
`<label>` that names the control.

**Not for:** validation or state messages (that is `app-field`'s `error`/
`hint`), or anything that must be visible without interaction — a danger
zone keeps its always-visible note.

---

### Settings design system

These primitives live in `frontend/src/app/shared/settings/` and are the
canonical building blocks every settings and admin section composes. The
"Grouped" look lives inside them and in the tokens — never in a feature `.scss`.
A feature section stacks these components; its own stylesheet holds only layout
glue. Introduced by #541.

### `<app-settings-stack>`

The vertical rhythm of one settings or admin page: a flex column that stacks the
page's groups with the one canonical gap. It is the template root of every
settings and admin route component, and it takes no inputs.

```html
<app-settings-stack>
  <app-settings-group …>…</app-settings-group>
  <app-settings-group …>…</app-settings-group>
</app-settings-stack>
```

**Why a container and not an adjacent-sibling rule.** The gap it replaces was
`app-settings-card + app-settings-card` in `src/styles/_base.scss`. A sibling
selector cannot cross a component host boundary, so the moment one card was
rendered from inside another component the gap vanished and the child carried a
compensating `margin-block-start` (#454). A stack's children are flex items, so
a child that is another component's host element is spaced identically to a
group written inline. **A section must never carry spacing to sit correctly in a
stack** — if it seems to need one, the stack is missing, not the margin.

`min-width: 0` on the host keeps a wide descendant (a scrolling table, the #409
run-history grid) from widening the page instead of scrolling inside its own
container.

### `<app-settings-group>`

One grouped-settings section: a header (a tinted icon chip, a title and an
optional caption) above a card surface that projects the group's rows or
disclosures.

| Input | Type | Default |
|---|---|---|
| `icon` | `string` | `''` — a Material Symbol name, rendered via `<app-icon>` |
| `title` | `string` | `''` |
| `caption` | `string` | `''` — omits the caption line when empty |

```html
<app-settings-group
  [icon]="'smart_toy'"
  [title]="'settings.ai.forYou.title' | transloco"
  [caption]="'settings.ai.forYou.caption' | transloco"
>
  <app-settings-row …>…</app-settings-row>
</app-settings-group>
```

`icon`, `title` and `caption` take already-translated strings, not i18n keys —
the component lives in `shared/` and must not hardcode a feature's translation
keys. The body (rows, disclosures) projects through the default `<ng-content>`
into a `.panel` card surface: `--surface-1`, `1px --border`, `--radius-lg`, and
the new `--panel-shadow` token (defined for both modes in `theme/tokens.scss`).

**Not for:** a section that is one flat card with no group header — that is
still `<app-settings-card>`.

A named `<ng-content select="[groupActions]">` slot sits at the trailing edge of
the header and takes one element — a "New" button, a filter group. The header
wraps, so actions that do not fit drop to their own line instead of crushing the
title. Projection matches only a **direct** child of the group (one `@if` deep
is tolerated, two silently fall through to the panel), so keep the marked
element at the top of the group's content.

### `<app-settings-row>`

One settings row: a title and an optional description stacked on the left, a
projected control on the right, vertically centred. It is the primitive a group
stacks.

| Input | Type | Default |
|---|---|---|
| `title` | `string` | `''` |
| `description` | `string` | `''` — omits the `.row-desc` element when empty |
| `stackable` | `boolean` | `false` |

```html
<app-settings-row
  [title]="'settings.ai.length.title' | transloco"
  [description]="'settings.ai.length.desc' | transloco"
  [stackable]="true"
>
  <app-info-tip rowTitleTip [text]="…" [label]="…" />
  <select>…</select>
</app-settings-row>
```

The control projects through the default `<ng-content>`. A named
`<ng-content select="[rowTitleTip]">` slot places an inline adornment
immediately after the title text inside `.row-title` — an `<app-info-tip>`, or a
small badge such as the preferences page's "Experimental" chip. The slot is
positional, not typed. The inset hairline divider between rows is
automatic — `:host(:not(:last-child))` draws it, so the parent group supplies
only the box. When `stackable`, on a narrow viewport (`bp.$bp-sm`) a select or
number control fills the row width while a toggle keeps its natural size.

### `<app-settings-save-bar>`

The shared save/reset affordance for a settings surface: an "unsaved changes"
indicator, a ghost Reset and a primary Save.

| Input / output | Type | Default |
|---|---|---|
| `dirty` | `boolean` | `false` |
| `saving` | `boolean` | `false` |
| `saveLabel` | `string` | `''` |
| `resetLabel` | `string` | `''` |
| `unsavedLabel` | `string` | `''` |
| `save` | `output<void>` | — |
| `reset` | `output<void>` | — |

```html
<app-settings-save-bar
  [dirty]="dirty()"
  [saving]="saving()"
  [saveLabel]="'settings.save' | transloco"
  [resetLabel]="'settings.reset' | transloco"
  [unsavedLabel]="'settings.unsaved' | transloco"
  (save)="persist()"
  (reset)="revert()"
/>
```

`saveLabel`, `resetLabel` and `unsavedLabel` take already-translated strings, not
i18n keys. The unsaved indicator shows only when `dirty`. Save is disabled unless
`dirty` and shows a spinner while `saving`. This bar does **not** own the success
confirmation: the consumer decides when a persist succeeded and fires the global
`shared/toast` itself. Coupling the toast in here would make the bar guess at an
outcome it never sees.

---

### `<app-action-sheet>` (via the `ActionSheet` service)

The row-menu surface for coarse pointers: a sheet pinned to the bottom of the
viewport, titled with the row it acts on. Opened through the `ActionSheet`
service — never instantiated in a template — because the open drawer carries a
transform, which would re-anchor any `position: fixed` child; the CDK overlay
escapes that.

```ts
this.sheet
  .open({ title: tag.name, actions: [{ id: 'edit', label: editLabel }] })
  .subscribe((choice) => { /* undefined on dismiss */ });
```

Labels and title are **already-translated strings** (shared component, no
feature keys). `danger: true` renders the action in the danger colour.
Dismissed by backdrop tap, Escape, or a downward swipe — all resolve
`undefined`.

**Not for:** fine-pointer surfaces (the sidebar keeps its inline `.pop`
popover on desktop) or anything with form controls — that is a dialog in
`<app-overlay-panel>`.

---

### `<app-spinner>`

The RSS mark, animating, as the app's loading indicator. Used for a fetch whose
result is not a list — a list should show `<app-skeleton>` instead, so the
layout does not jump when rows arrive.

| Input | Type | Default |
|---|---|---|
| `size` | `number` (px) | `18` |
| `decorative` | `boolean` | `false` — keeps `role="status"` and the "Loading" label |
| `animate` | `boolean` | `true` |

```html
@if (loading()) {
  <app-spinner />
}
```

`decorative` is for the brand mark in the top bar, which is not announcing a
load. `animate: false` holds the mark still in the signal colour — the brand
mark only animates while a refresh is actually running.

**Not for:** a list load. Use `<app-skeleton>`.

---

### `<app-skeleton>`

Placeholder rows for a list that is still loading. Sized from the comfortable
row-density tokens, so the layout does not shift when the real rows arrive.

| Input | Type | Default |
|---|---|---|
| `label` | `string` (required) | — |
| `rows` | `number` | `3` |

```html
@if (store.loading()) {
  <app-skeleton [label]="'settings.tags.loading' | transloco" [rows]="4" />
}
```

`label` takes an already-translated string, not an i18n key. The placeholder
rows are `aria-hidden`; the `role="status"` label is what gets announced. The
pulse animation is disabled under `prefers-reduced-motion`.

**Not for:** a non-list fetch — use `<app-spinner>`, which does not pretend to
know the shape of what is coming.

---

### `<app-search-field>`

The entry-search input. It owns every timing rule the search has, so no caller
repeats one: a 300 ms debounce, the three-character floor, the too-short hint,
the trailing clear-or-close button, and `Escape`.

| Input | Type | Default |
|---|---|---|
| `term` | `string` | `''` — the term currently in the URL |
| `dismissible` | `boolean` | `false` — whether this mount can be left |

| Output | Type | Fires |
|---|---|---|
| `search` | `string` | a settled term, or `''` when cleared |
| `dismissed` | `void` | the user asked to leave a field that was already empty |

```html
@if (!screen.isNarrow()) {
  <app-search-field [term]="selection().term ?? ''" (search)="search.emit($event)" />
}
```

The field emits; it never navigates. The shell owns the navigation, so both
mount points — the desktop sidebar and the mobile header bar — drive one code
path.

`search` emits the settled term after the debounce, but a **clear** emits `''`
at once: waiting 300 ms to leave a search makes the view feel stuck. The
component tracks the term already in effect and moves it from both directions —
its own emissions and the `term` input — so a term retyped after clearing, or
after a Back navigation, still emits.

`dismissed` exists so the mobile bar can implement the two-step exit (first
step clears, second closes) without the field knowing a bar exists, or the bar
reading the field's text. Both steps run through one control and one contract:
`Escape`, and the field's own trailing ✕.

`dismissible` decides only whether that ✕ survives an empty field. The mobile
bar sets it, because on a phone the ✕ is the whole exit — there is no `Escape`
key, and the bar deliberately carries no close button of its own beside the
field's (#550: two ✕ side by side, one clearing and one closing, read as one
control that behaved differently depending on where it was tapped). The
sidebar's permanent mount leaves it `false`: there, the ✕ appears with text and
goes with it. On a coarse pointer the button's hit box grows to `--tap-target`
while the glyph stays put.

A bare `/` anywhere in the document focuses the field. It is ignored when a
modifier is held or when the event target is an `input`, `textarea` or
`contenteditable` — including this field's own input, where `/` types a slash.

**Not for:** any other filter. It is bound to the entry search's rules.

---

### `<app-marked-text>`

Renders text with the matched search terms wrapped in `<mark>`.

| Input | Type | Default |
|---|---|---|
| `text` | `string` | `''` |
| `terms` | `string[]` | `[]` |

```html
<app-marked-text [text]="entry().title" [terms]="terms()" />
```

The marking is a pure function returning text segments, which the template
renders as elements. **There is no `innerHTML` anywhere in this path, and there
must never be** — an entry title is feed-supplied, so a title containing markup
has to render as visible text.

Used on the entry row's title and lead paragraph, which are the two fields the
search actually matches on. Do not mark the feed name or the date; they were
never searched, and a mark there claims a match that does not exist.

Matching is case-insensitive and preserves the original casing. Regular
expression metacharacters in a term match literally, so a search for `c++`
behaves.

**Not for:** general emphasis. Use it only where a term genuinely matched.

---

### `<app-entry-meta>`

The line a magazine card ends on: `app-source-tags` on the left,
`app-entry-actions` right-aligned against them. Six of the seven magazine
blocks use it — hero, wide, split, thumb, kicker, quote.

| Input | Type | Default |
|---|---|---|
| `entry` | `EntryDto` (required) | — |
| `tags` | `SubscriptionTagDto[]` | `[]` |

| Output | Type | Fires |
|---|---|---|
| `favorite` | `output<EntryDto>` | the favorite button is pressed |
| `keep` | `output<EntryDto>` | the keep button is pressed |
| `read` | `output<EntryDto>` | the mark-read button is pressed |

```html
<app-entry-meta
  [entry]="entry()"
  [tags]="tags()"
  (favorite)="favorite.emit($event)"
  (keep)="keep.emit($event)"
  (read)="read.emit($event)"
/>
```

Two pieces of its geometry are load-bearing, and are why this is a component
rather than a row assembled per block. `align-items: flex-end` keeps the icons
level with the **last** line of a wrapping pill list, so the card's bottom
edge stays the reference. `margin-top: auto` drops the whole row to the
bottom of a card whose image is taller than its text — `split` and `thumb`,
which stretch their body for exactly this — and is inert everywhere else.

**Not for:** `entry-compact`, which projects `app-entry-actions` directly onto
its kicker line instead of using this row — see the exception in
[§6](#6-deliberate-exceptions).

### `<app-entry-actions>`

The three per-entry actions — favorite, keep, mark read — as one control
cluster. `app-entry-meta` renders it inline; `entry-compact` uses it directly,
projected into the kicker line (see the exception in
[§6](#6-deliberate-exceptions)). It renders with no wrapper element — `:host`
is `inline-flex` — because a wrapper would give the HTML parser a block-level
child to choke on where the component lands inside a `<p>`.

| Input | Type | Default |
|---|---|---|
| `entry` | `EntryDto` (required) | — |

| Output | Type | Fires |
|---|---|---|
| `favorite` | `output<EntryDto>` | the favorite button is pressed |
| `keep` | `output<EntryDto>` | the keep button is pressed |
| `read` | `output<EntryDto>` | the mark-read button is pressed |

```html
<app-entry-actions
  [entry]="entry()"
  (favorite)="favorite.emit($event)"
  (keep)="keep.emit($event)"
  (read)="read.emit($event)"
/>
```

Clicks stop propagating: the card around the buttons is itself clickable, and
would otherwise open the entry instead of toggling the flag. Favorite and keep
light up in the accent colour when on; the read button instead swaps its icon,
because most cards are already read, and accenting that state would light up
the whole page for no reason.

On a coarse pointer the buttons grow to `--tap-target` height on negative
margins alone, so a card never gets taller on a phone, and the gap between
them widens to exactly twice a button's touch padding, so the enlarged hit
boxes tile edge to edge — a tap on the star must never land on keep.

---

## 3. Conventions

### Density

Every list, rail and picker row derives its padding from one of the two token
pairs in [§1](#row-density) — never from raw `--space-*`, and never from a
literal. If a new surface genuinely fits neither, that is a design conversation,
not a third pair invented in a component stylesheet.

**Touch density is a pointer decision, applied locally.** The reader sidebar
raises its rows and hit zones to `--tap-target` (44px) under
`@media (pointer: coarse)` in its own stylesheet (#185). The compact density
tokens themselves never grow — they are shared by the discover rails and the
admin catalog, which stay compact. A new touch surface repeats the pattern
locally: key on `pointer: coarse` (capability), never on viewport width
(presentation), and size hit areas with `--tap-target`.

### Sticky and scroll

**Stickiness lives on the flex-child host, never on an inner wrapper.** A sticky
element inside a content-height host has no room to stick: the host is exactly as
tall as its content, so the sticky element hits the bottom of its containing
block immediately and scrolls away with its parent. This is exactly the
`category-rail` bug #126 reported. The fix is `position: sticky` on `:host`, with
`align-self: flex-start` and a `max-height` so the rail can scroll internally
when the categories outrun it:

```scss
:host {
  position: sticky;
  top: 0;
  display: block;
  align-self: flex-start;
  max-height: 100%;
  overflow-y: auto;
  overscroll-behavior: contain;
}
```

**A sticky box only travels inside its containing block's _content_ box, so
padding on the parent strands it early.** The article's progress bar is sticky to
the bottom of the reading pane, and the reading tail used to add half a viewport
of `padding-bottom` to that same parent: the bar therefore ran out of containing
block half a screen above the true bottom and scrolled out of sight over the
tail, exactly where the reader was finishing the article (#238). The fix is to
put the space in flow instead — the tail moved onto the `<article>`, which leaves
the parent's height identical and gives the sticky child the whole range.

**A scroll cue on a pane is sticky, never fixed.** `position: fixed` resolves
against the viewport, so a bar meant for one pane runs on under its neighbours on
the split layout — and any transformed ancestor re-anchors it to that ancestor
instead (the trap #100 documents for the back-to-top button, and the reading pane
carries a transform through its return gestures). Sticky is scoped to the pane by
construction and immune to both.

**Every internal scroller gets `overscroll-behavior: contain`.** Reaching the end
of a rail must not hand the wheel to the panel body behind it — the sections
would scroll and the rail would appear to jump to a category the reader never
picked. In use by the overlay panel body, the category rail, the category chips,
the icon picker grid, and the add-feed and edit-subscription dialogs.

**Content beneath a floating bar offsets by the measured height plus `--bar-gap`,
never by a literal:**

```scss
padding-top: calc(var(--app-bar-h, var(--bar-h)) + var(--list-bar-h) + var(--bar-gap));
```

One value (`--bar-gap`) tunes the gap everywhere.

### Overlay

Every interrupt surface renders inside `<app-overlay-panel>`. Every
`dialog.open()` passes `panelClass: 'app-dialog'` — the class lands on the CDK's
`.cdk-overlay-pane` and is what lets `styles.scss` size the pane to the viewport
on a phone, which the full-screen panel needs to reach the screen at all.
Per-consumer width and max-height come from `--panel-w` / `--panel-max-h` set on
the consumer's host.

The CDK's structural overlay CSS is imported by `src/styles.scss`, not by
`angular.json`, so every build configuration gets it. Without it a dialog renders
as a plain block appended after the 100vh reader shell — a full viewport below
the fold (#85).

### Forms

`<app-field>` wraps a labelled control; the native control keeps its own
`formControlName` and its own attributes. Global control styling — border,
radius, height, padding, focus, disabled — lives in `styles/_controls.scss`,
because Angular's `ViewEncapsulation` does not style projected content, and
because a bare `<input>` outside a field should still look like the rest of the
app.

### Settings (#541)

**Save by control type.** A toggle or a select saves on change — an instant
persist confirmed by the one global toast. A text or number field is
dirty-tracked behind the `<app-settings-save-bar>`'s explicit Save, with the
"unsaved changes" indicator until the user saves. This is the settled save
model for settings; do not mix the two on one control.

**Feature sections compose the primitives, they never restyle.** The Grouped
look lives in the `shared/settings/` primitives and the tokens. A feature
settings section composes `<app-settings-group>` + `<app-settings-row>` +
`<app-disclosure appearance="drill-in">` + `<app-settings-save-bar>` +
`<app-info-tip>`. It does not re-implement the look in its own `.scss`, which
holds only layout glue. If a section needs a new visual pattern, that pattern
becomes (or extends) a `shared/settings/` primitive first.

---

## 4. Enforcement and escape hatches

`frontend/.stylelintrc.json`, run by `npm run check` (which is `ng lint` +
`prettier --check` + `stylelint "src/**/*.scss"` + `jest`).

| Rule | Effect |
|---|---|
| `color-no-hex` | no hex literals — colours come from tokens |
| `declaration-property-unit-allowed-list` | `padding*`, `margin*`, `gap`/`row-gap`/`column-gap`, `font-size`, `border-radius` accept only `%`/`em`/`rem` — i.e. a raw `px` is a failure, and a `var(--space-*)` is not a unit at all, so tokens pass |
| ↳ same rule, sizing props | `width`/`height`/`min-*`/`max-*` additionally accept `ch`/`vw`/`vh`/`dvw`/`dvh`/`fr` |
| `media-feature-name-unit-allowed-list` | `@media (width …)` accepts **no** unit — forces `bp.$bp-*` |

**Exempt** (all three rules disabled): `src/app/theme/**/*.scss`,
`src/styles/**/*.scss`, `src/styles.scss`. Those files *define* the tokens and
the global chrome, so they are where the literals belong.

### The escape hatch

A tuned component dimension that is genuinely not a spacing value — a panel
measure, a rail width, a menu min-width — disables the rule for one line, with a
reason:

```scss
/* stylelint-disable-next-line declaration-property-unit-allowed-list --
   tuned component dimension, not a spacing value. */
width: 220px;
```

The reason after `--` is mandatory in practice: without it the next reader
cannot tell a considered exception from an unmigrated literal.

### Standing rule: styles go in a `.scss` file

**Stylelint cannot parse `.ts`.** No `customSyntax` is installed, so it throws on
the first line of TypeScript and never reaches an inline `styles:` block:

```
src/app/shared/icon-picker/icon-picker.component.ts
  3:3  ✖  Unknown word booleanAttribute  CssSyntaxError
```

Therefore **component styles must live in a sibling `.scss` file referenced by
`styleUrl`**, or they are silently unenforced — no hex check, no `px` check, no
breakpoint check. All 48 styled components in `src/app` do this today; keep it
that way.

And: **never pass a `.ts` glob to stylelint.** It does not "lint the inline
styles too" — it fails the whole run with a `CssSyntaxError` per file. The
`npm run stylelint` glob is `src/**/*.scss` and should stay that way.

(Inline `template:` is fine — stylelint has no opinion on templates, and
`<app-icon-picker>` keeps one.)

---

## 5. Magazine blocks

The reader's magazine list (`frontend/src/app/reader/magazine/`) plans entries
onto eight block types, introduced by #148 to replace a layout that had
collapsed to two-thirds compact rows. `magazine-block.ts`'s `BLOCK_HEIGHT`
holds the measured height each contributes to the planner's per-page budget;
`magazine-planner.ts`'s `fits()` decides whether a given entry may fill a
slot of that kind. Heights are measured at a 390px viewport width and are
relative units for the budget, not a layout guarantee.

| Block | Height | Image | Fills when |
|---|---|---|---|
| **Hero** | 463px | full-width, adaptive `aspect-ratio` from the persisted dimensions (fallback 16/9) | image ≥ 500px wide, or width unknown but `imageUrl` is persisted |
| **Wide** | 260px | full-width band at 3:1 | image ≥ 400px wide, or width unknown but `imageUrl` is persisted |
| **Quote** | 180px | suppressed — first sentence set in `--font-voice` instead | snippet text ≥ 300 characters |
| **Split** | 150px | side image at 38% of the column (148px mobile / 258px desktop) | image ≥ 300px wide, or width unknown but `imageUrl` is persisted |
| **Kicker** | 140px | none — oversized title only | always |
| **Thumb** | 90px | fixed 88px box, `aspect-ratio: 4 / 3` | any persisted image |
| **Compact** | 66px | none | always |
| **Group** (source digest) | ~300px (not in `BLOCK_HEIGHT` — it consumes entries directly, not a template slot) | none — each row inside is a `<app-entry-compact>` | a same-source run of ≥ 3 entries whose source holds under 40% of the loaded entries |

**"Width unknown" is not the same treatment everywhere.** `fits()` reads the
persisted `imageWidth` and treats `0` (unknown) differently per kind. The three
large image blocks — `hero`, `wide` and `split` — trust an unknown width only
when `imageUrl` itself is a persisted field, because an inline `<img>` with
neither a persisted URL nor a known width is, in practice, a ~148px archive
thumbnail: exactly what used to produce heroes and bands with no real picture.
`thumb` is the exception — it accepts any image regardless of width, since its
box is fixed at 88px, so even that miniature thumbnail fills it cleanly, which
is precisely why it is the demotion target for the larger image blocks.

An entry that cannot fill its planned slot demotes transitively:
`hero → wide → split → thumb → compact`, and `quote → kicker → compact` —
never one step, since demoting a hero straight to `wide` in an image-less
view would still leave an image block with no image.

---

## 6. Deliberate exceptions

Each of these was decided during #126 and looks like an oversight from the
outside. They are not. Read the reason before "fixing" one.

**`settings/tags-section` renders the colour dot *and* the icon.** It shows a
`--space-2` swatch followed by an `<app-icon>` — a swatch-plus-glyph pair, not
`<app-tag-glyph>`'s either/or fallback. Converting it to `<app-tag-glyph>` would
change the design: the colour would stop being visible on tags that have an
icon, which on the tag-management screen is exactly the information the user is
there to edit.

**`admin-catalog`'s feed and category rows are a dense data grid, not a form.**
Most controls there have no visible label and no translation key — they are
identified by column position and `aria-label`. They take the global control
styling from `styles/_controls.scss` but not `<app-field>`, whose stacked label
would roughly triple the row height and destroy the grid. The two genuinely
labelled controls on that page (the OPML import file input and the import-mode
select) *do* use `<app-field>`.

**`discover`'s `.mark` keeps an explicit `--icon-sm` footprint.** It reserves
space for a check icon that is usually absent. Sizing it from its content would
let the card titles reflow the moment a card is picked. The token is doing
layout, not icon sizing, which is why it stays even though no icon renders.

**`_controls.scss` scopes `width: 100%` to `app-field` descendants.** Width is
layout, not chrome, so it belongs to the field rather than to the control. Set
globally it stretched the admin grid's selects to the full row width and pushed
each onto a line of its own.

**`_controls.scss` scopes its `:focus` rule away from checkbox, radio and colour
inputs.** Only controls that actually carry a border may trade the focus outline
for an accent border. The unscoped
`input:focus { border-color: …; outline: none }` has specificity (0,1,1) and
outranks `_base.scss`'s `:focus-visible { outline: … }` at (0,1,0) — which left
checkboxes, radios and colour swatches, none of which have a border to tint, with
no visible keyboard focus at all. That is a WCAG 2.4.7 failure, not a cosmetic
one.

**`entry-list`'s `- 44px` and `reader-header`'s `top: 44px` are positioning
offsets that coincidentally share the `--tap-target` number.** The first is how
far the pull-to-refresh chip parks above the bars; the second is how far the
account dropdown hangs below the account button's top edge. Neither is a touch
target. Tokenising them would couple two unrelated numbers, so that the day
`--tap-target` moves to 48px the chip would silently park in the wrong place.
Both sites carry a comment saying so.

**`ButtonComponent`'s hover rules are per-variant, not a blanket
`button:hover:not(:disabled)`.** `:not()` carries its argument's specificity, so
a blanket rule scores (0,2,1) and outranks `button.danger` at (0,1,1) — it would
repaint a destructive button's border in the accent colour on hover. Each variant
gets its own hover block instead.

**`entry-thumb.component.scss`'s `.img` fixes an 88px flex-basis.** It is the
thumbnail's tuned box proportion, not a spacing value the density scale
models, hence the `stylelint-disable-next-line
declaration-property-unit-allowed-list` comment at the declaration. Tokenising
it as a `--space-*` step would couple an unrelated visual constant to the
spacing scale, so the day spacing changes, the thumbnail would resize with it
for no reason.

**`entry-split.component.scss`'s `.img` fixes a 38% flex-basis.** It is the
split block's defining proportion — the direct fix for "the medium widget
shows images too small" (#148, was 88px) — not a spacing value either. It
carries no `stylelint-disable` comment because `flex`/`flex-basis` is not one
of the properties `declaration-property-unit-allowed-list` governs at all, but
the reasoning is identical to the thumb box above and is recorded in a plain
comment at the declaration so a future reader does not migrate it to a
`--space-*` token anyway.

**`entry-compact` hangs its actions on the kicker line, not on an
`app-entry-meta` row.** A source group shows up to four compact rows; a meta
row each would add a full line per item and inflate the group, while the
right-hand end of the kicker line is already empty — it only holds the time.
So compact projects `app-entry-actions` into the kicker line and leaves its
tag row pills-only. The projected element must stay inline: the kicker is a
`<p>`, and the HTML parser closes a paragraph at a block-level child, which
drops the icons onto a second line without any error.

---

## 7. Adding a new surface

1. **Reach for the shared component before writing markup.** A labelled control
   is `<app-field>`. A dialog is `<app-overlay-panel>` opened with
   `panelClass: 'app-dialog'`. An action button is `<app-button>`. A tag or
   category is `<app-tag-glyph>`. An icon is `<app-icon>` at a named size.
2. **Derive every spacing value from a token.** No raw `px` for padding, margin,
   gap, font-size or border-radius. If you need a tuned dimension, use the
   documented escape hatch *with a reason*.
3. **`@use '<rel>/theme/breakpoints' as bp;`** and write `bp.$bp-sm/md/lg`. Never
   a literal in a media query — it will not lint, and a custom property will not
   match.
4. **Pick a density pair for rows** — compact or comfortable — and use it for
   every row on the surface.
5. **Put stickiness on the host**, not an inner wrapper, and give any internal
   scroller `overscroll-behavior: contain`.
6. **Styles go in a sibling `.scss` file**, never inline in the `.ts` — inline
   styles are invisible to Stylelint.
7. **Run `npm run check`** from `frontend/`.

## 8. Adding a new settings section

Compose the `shared/settings/` primitives; do not restyle the look (see
[§3 Settings](#settings-541)).

1. **One `<app-settings-group>` per concern** — an icon, a title, an optional
   caption, and the rows inside it.
2. **One `<app-settings-row>` per control**, with the control projected on the
   right. Add an `<app-info-tip rowTitleTip>` for help on the title.
3. **`<app-disclosure appearance="drill-in">`** for an advanced or Expert
   grouping that expands in place inside the group.
4. **`<app-settings-save-bar>`** for the typed fields (text, number). Toggles
   and selects save on change instead — confirm those with the global toast.

```html
<app-settings-group
  [icon]="'smart_toy'"
  [title]="'settings.ai.forYou.title' | transloco"
>
  <app-settings-row [title]="'settings.ai.enabled.title' | transloco">
    <app-info-tip
      rowTitleTip
      [text]="'settings.ai.enabled.info' | transloco"
      [label]="'settings.ai.enabled.title' | transloco"
    />
    <input type="checkbox" [checked]="enabled()" (change)="toggle()" />
  </app-settings-row>

  <app-disclosure
    appearance="drill-in"
    [label]="'settings.ai.expert.title' | transloco"
  >
    <app-settings-row
      [title]="'settings.ai.temperature.title' | transloco"
      [stackable]="true"
    >
      <input type="number" [value]="temperature()" (input)="onTemperature($event)" />
    </app-settings-row>
  </app-disclosure>
</app-settings-group>

<app-settings-save-bar
  [dirty]="dirty()"
  [saving]="saving()"
  [saveLabel]="'settings.save' | transloco"
  [resetLabel]="'settings.reset' | transloco"
  [unsavedLabel]="'settings.unsaved' | transloco"
  (save)="persist()"
  (reset)="revert()"
/>
```
