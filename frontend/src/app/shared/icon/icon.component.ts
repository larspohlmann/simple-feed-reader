import { ChangeDetectionStrategy, Component, input } from '@angular/core';

export type IconSize = 'text' | 'xs' | 'sm' | 'md' | 'lg';

/**
 * Maps the named size onto its token. Kept here so no consumer writes a px.
 *
 * `text` is not a step on the scale: it means "match the text you sit in".
 * An icon inline in a sentence -- the open-in-new after a link, the glyph in a
 * tag pill -- has no business picking a fixed size, because the thing it must
 * agree with is the surrounding type, not the scale. Picking the nearest step
 * instead is what made those two icons look oversized in #126.
 *
 * It is 0.85em, not 1em, because a Material Symbol fills its em box while the
 * lowercase text beside it reaches barely half of it. Matching the font sizes
 * numerically still leaves the glyph looking about twice the weight of the
 * letters; matching what the eye sees means going under.
 */
export const ICON_SIZE_TOKEN: Record<IconSize, string> = {
  text: '0.85em',
  xs: 'var(--icon-xs)',
  sm: 'var(--icon-sm)',
  md: 'var(--icon-md)',
  lg: 'var(--icon-lg)',
};

/* The size lands on the host rather than the glyph span so the two consumers
   that own a genuinely fluid pixel box — app-favicon and app-user-avatar —
   can override it with a template style binding, which outranks a host
   binding. Every other consumer names a size and never writes a px. */
@Component({
  selector: 'app-icon',
  templateUrl: './icon.component.html',
  styleUrl: './icon.component.scss',
  host: { '[style.font-size]': 'fontSize()' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class IconComponent {
  readonly name = input.required<string>();
  readonly size = input<IconSize>('md');

  protected fontSize(): string {
    return ICON_SIZE_TOKEN[this.size()];
  }
}
