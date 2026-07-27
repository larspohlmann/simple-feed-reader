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
        <app-icon [name]="value() || 'block'" [size]="18" />
      </span>
      <app-icon class="caret" name="expand_more" [size]="16" />
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
          <app-icon name="block" [size]="18" />
        </button>
        @for (name of icons; track name) {
          <button
            type="button"
            class="opt"
            [class.on]="value() === name"
            [attr.aria-label]="name"
            (click)="choose(name)"
          >
            <app-icon [name]="name" [size]="18" />
          </button>
        }
      </div>
    }
  `,
  styles: `
    :host {
      position: relative;
      display: inline-block;
    }
    .trigger {
      display: inline-flex;
      gap: var(--space-1);
      align-items: center;
      height: var(--space-5);
      padding: 0 var(--space-1) 0 var(--space-2);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: var(--surface-1);
      color: var(--text-muted);
      cursor: pointer;
    }
    .trigger.open,
    .trigger:hover {
      border-color: var(--border-strong);
    }
    .glyph {
      display: inline-flex;
    }
    .pop {
      position: absolute;
      top: calc(100% + 4px);
      left: 0;
      z-index: 20;
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-1);

      /* stylelint-disable-next-line declaration-property-unit-allowed-list --
         tuned popover width, not a spacing value. */
      width: 248px;

      /* stylelint-disable-next-line declaration-property-unit-allowed-list --
         tuned popover max-height, not a spacing value. */
      max-height: 220px;
      padding: var(--space-2);
      overflow-y: auto;
      overscroll-behavior: contain;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: var(--surface-2);
      box-shadow: 0 12px 32px rgb(0 0 0 / 24%);
    }
    .opt {
      display: inline-flex;
      padding: var(--space-2);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: var(--surface-1);
      color: var(--text-secondary);
      cursor: pointer;
    }
    .opt.on {
      border-color: var(--accent);
      background: var(--accent-soft);
      color: var(--accent);
    }
  `,
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
