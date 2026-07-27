// src/app/shared/icon-picker/icon-picker.component.ts
import {
  booleanAttribute,
  ChangeDetectionStrategy,
  Component,
  computed,
  ElementRef,
  HostListener,
  inject,
  input,
  model,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../icon/icon.component';
import { TAG_ICONS } from '../icon-choices';

/**
 * An icon selector over the curated Material Symbols — the same set the reader's
 * tag form and the admin catalog both offer, so a tag and a category are picked
 * from one palette. Two-way bound on `value`; the empty string means "no icon".
 *
 * Two framings of one grid, chosen with `inline`:
 * - popover (default) for a dense row like the admin catalog, where a compact
 *   trigger has to sit between other controls;
 * - inline for a form with room to spare, where the grid is worth a permanent
 *   place and selection should cost one click.
 */
@Component({
  selector: 'app-icon-picker',
  standalone: true,
  imports: [IconComponent, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.inline]': 'inline()' },
  template: `
    @if (!inline()) {
      <button
        type="button"
        class="trigger"
        [class.open]="open()"
        aria-haspopup="listbox"
        [attr.aria-expanded]="open()"
        [attr.aria-label]="'iconPicker.choose' | transloco"
        (click)="toggle()"
      >
        <span class="glyph" [style.color]="color() || 'var(--text-muted)'">
          <app-icon [name]="value() || 'block'" size="md" />
        </span>
        <app-icon class="caret" name="expand_more" size="sm" />
      </button>
    }

    @if (expanded()) {
      <div class="grid" [class.pop]="!inline()" role="listbox">
        <button
          type="button"
          class="opt"
          [class.on]="!value()"
          [attr.aria-label]="'iconPicker.none' | transloco"
          (click)="choose('')"
        >
          <app-icon name="block" size="md" />
        </button>
        @for (name of icons; track name) {
          <button
            type="button"
            class="opt"
            [class.on]="value() === name"
            [attr.aria-label]="name"
            (click)="choose(name)"
          >
            <app-icon [name]="name" size="md" />
          </button>
        }
      </div>
    }
  `,
  styleUrl: './icon-picker.component.scss',
})
export class IconPickerComponent {
  /** Two-way bound glyph name; the empty string is "no icon". */
  readonly value = model<string>('');
  /** Tints the trigger glyph — usually the category's colour. */
  readonly color = input<string | null>(null);
  /** Renders the grid in place instead of behind a trigger. */
  readonly inline = input(false, { transform: booleanAttribute });

  readonly icons = TAG_ICONS;
  readonly open = signal(false);

  /** Inline is permanently expanded; the popover only while it is open. */
  protected readonly expanded = computed(() => this.inline() || this.open());

  private readonly host = inject(ElementRef<HTMLElement>);

  toggle(): void {
    this.open.update((isOpen) => !isOpen);
  }

  choose(name: string): void {
    this.value.set(name);
    this.open.set(false);
  }

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (this.open() && !this.host.nativeElement.contains(event.target as Node)) {
      this.open.set(false);
    }
  }

  /**
   * Escape dismisses an open popover, and is swallowed so it does not also
   * reach the CDK dialog listening on `body` and close the whole form. Inline
   * mode never opens, so the keypress passes through untouched — there is
   * nothing to dismiss there and Escape still belongs to the dialog.
   */
  @HostListener('keydown.escape', ['$event'])
  onEscape(event: Event): void {
    if (!this.open()) return;
    this.open.set(false);
    event.stopPropagation();
  }

  /** Fallback for an Escape pressed while focus sits outside the picker. */
  @HostListener('document:keydown.escape')
  onEscapeElsewhere(): void {
    if (this.open()) this.open.set(false);
  }
}
