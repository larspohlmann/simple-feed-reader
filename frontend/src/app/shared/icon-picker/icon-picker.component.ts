// src/app/shared/icon-picker/icon-picker.component.ts
import {
  ChangeDetectionStrategy,
  Component,
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
 * A compact icon selector: a trigger showing the current glyph, and a popover
 * grid of the curated Material Symbols — the same set the reader's tag form
 * offers, so a category and a tag are picked from one palette. Two-way bound on
 * `value`; the empty string means "no icon".
 */
@Component({
  selector: 'app-icon-picker',
  standalone: true,
  imports: [IconComponent, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
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

    @if (open()) {
      <div class="pop" role="listbox">
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

  readonly icons = TAG_ICONS;
  readonly open = signal(false);

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

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.open()) this.open.set(false);
  }
}
