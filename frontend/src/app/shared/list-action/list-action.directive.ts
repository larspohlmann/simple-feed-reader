import { Directive } from '@angular/core';

/**
 * Marks an `<a>` or `<button>` as a list-header action — the icon-and-label
 * controls in the list header's tools row: Edit, the unread switch, Mark all
 * read, Refresh, Save search. They looked "all over the place" because each
 * carried its own copy of the same flex/gap/colour/size rules and the copies
 * drifted (one kept a stray padding). This gives them one owner (#617).
 *
 * The look itself lives in the global `styles/_list-action.scss`, keyed on the
 * `list-action` class this stamps — global, not component-scoped, for the same
 * reason `styles/_controls.scss` is: several of these controls are projected
 * through the list's action outlets, and ViewEncapsulation does not reach
 * projected content. The directive keeps each consumer on its own element and
 * behaviour (the switch stays an anchor with `role="switch"`, Refresh stays a
 * button that disables and spins); only the look is shared.
 */
@Directive({
  selector: 'a[appListAction], button[appListAction]',
  host: { class: 'list-action' },
})
export class ListActionDirective {}
