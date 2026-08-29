import { Directive } from '@angular/core';

/**
 * Marks a `<button>` as a small square icon-only action — move up/down, edit,
 * delete — the kind the Organise page's feed rows and tag panels both carry.
 * The CSS lived verbatim in `organise-feed-row.component.scss` and
 * `organise-tag-group.component.scss`; this gives it one owner instead, the
 * same reasoning as `ListActionDirective` (#617) for the list header's own
 * controls.
 *
 * Not a fit for `ListActionDirective` itself: that one wears an accent-
 * coloured label with a gap and a mobile "lose the label, keep a bordered tap
 * target" transform, none of which apply here — this button never carries a
 * label at all, stays a fixed `--icon-lg` square, and uses the row's muted
 * icon colour, not the accent link colour.
 *
 * The look lives in the global `styles/_icon-button.scss`, keyed on the `ib`
 * class this stamps — global, not component-scoped, because two SIBLING
 * components (the row and the panel) cannot share a component-scoped
 * stylesheet; `ListActionDirective`'s doc comment gives the same reasoning
 * for `_list-action.scss`.
 */
@Directive({
  selector: 'button[appIconButton]',
  host: { class: 'ib' },
})
export class IconButtonDirective {}
