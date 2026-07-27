import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { ICON_SIZE_TOKEN, IconComponent, IconSize } from '../icon/icon.component';

/**
 * The one way to render a tag or catalog category.
 *
 * A tag carries an optional Material Symbol and an optional colour. With a
 * glyph it renders tinted; without one it falls back to a colour dot, so an
 * icon-less tag is still identifiable at a glance. Callers that highlight a
 * selected row pass the highlight colour in `color` ('currentColor', say) —
 * both branches honour it, which is why the ternary no longer has to be
 * duplicated across the glyph and the dot.
 *
 * The host is a square of the named size whichever branch renders, because a
 * dot is far smaller than a glyph and lists mix the two freely. Owning the
 * footprint here is what lets every consumer drop the fixed-width slot it used
 * to need to keep tag names on one left edge.
 */
@Component({
  selector: 'app-tag-glyph',
  imports: [IconComponent],
  templateUrl: './tag-glyph.component.html',
  styleUrl: './tag-glyph.component.scss',
  host: { '[style.width]': 'box()', '[style.height]': 'box()' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TagGlyphComponent {
  readonly name = input<string | null>(null);
  readonly color = input<string | null>(null);
  readonly size = input<IconSize>('md');

  protected tint(): string {
    return this.color() ?? 'var(--text-muted)';
  }

  protected box(): string {
    return ICON_SIZE_TOKEN[this.size()];
  }
}
