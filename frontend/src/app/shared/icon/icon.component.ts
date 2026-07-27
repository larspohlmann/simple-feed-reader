import { ChangeDetectionStrategy, Component, input } from '@angular/core';

export type IconSize = 'xs' | 'sm' | 'md' | 'lg';

/** Maps the named size onto its token. Kept here so no consumer writes a px. */
const SIZE_TOKEN: Record<IconSize, string> = {
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
    return SIZE_TOKEN[this.size()];
  }
}
