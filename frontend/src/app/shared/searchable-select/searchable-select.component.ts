// src/app/shared/searchable-select/searchable-select.component.ts
import {
  booleanAttribute,
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  model,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { DismissOnOutsideDirective } from '../dismiss-on-outside.directive';
import { IconComponent } from '../icon/icon.component';

export interface SelectOption {
  readonly value: string;
  readonly label: string;
}

/**
 * A select for lists too long to scan: a filter box above the options.
 *
 * Not a native `<select>` — a provider can offer hundreds of models, and a
 * native list has no filter. Not a `ControlValueAccessor` either, following
 * `app-icon-picker`: the consumers here bind a signal, and the forms API would
 * be machinery nobody uses.
 *
 * The active index is kept inside the FILTERED list, not the full one, so
 * narrowing the filter can never leave the highlight pointing at an option the
 * user can no longer see.
 */
@Component({
  selector: 'app-searchable-select',
  imports: [DismissOnOutsideDirective, IconComponent, TranslocoPipe],
  templateUrl: './searchable-select.component.html',
  styleUrl: './searchable-select.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SearchableSelectComponent {
  readonly options = input.required<readonly SelectOption[]>();
  readonly value = model<string | null>(null);
  readonly placeholder = input('');
  readonly inputId = input.required<string>();
  readonly disabled = input(false, { transform: booleanAttribute });

  readonly open = signal(false);
  readonly filter = signal('');
  readonly activeIndex = signal(0);

  readonly matches = computed(() => {
    const needle = this.filter().trim().toLowerCase();
    const all = this.options();
    if (!needle) return all;
    return all.filter((option) => option.label.toLowerCase().includes(needle));
  });

  readonly selectedLabel = computed(
    () => this.options().find((option) => option.value === this.value())?.label ?? '',
  );

  /** Stable id for the popup listbox, derived from `inputId` so two instances
   * on one page never collide. */
  readonly listboxId = computed(() => `${this.inputId()}-listbox`);

  /** The `aria-activedescendant` the search input reports: null while closed
   * or while the filter has nothing left to highlight, so a screen reader
   * never announces a highlight that isn't there. */
  readonly activeOptionId = computed(() => {
    if (!this.open() || this.matches().length === 0) return null;
    return this.optionId(this.activeIndex());
  });

  optionId(index: number): string {
    return `${this.inputId()}-option-${index}`;
  }

  toggle(): void {
    if (this.disabled()) return;
    this.open.update((wasOpen) => !wasOpen);
    this.filter.set('');
    this.activeIndex.set(0);
  }

  close(): void {
    this.open.set(false);
  }

  applyFilter(text: string): void {
    this.filter.set(text);
    this.activeIndex.set(0);
  }

  choose(option: SelectOption): void {
    this.value.set(option.value);
    this.close();
  }

  onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') return this.move(1, event);
    if (event.key === 'ArrowUp') return this.move(-1, event);
    if (event.key === 'Enter') return this.takeActive(event);
    if (event.key === 'Escape') this.close();
  }

  private move(step: number, event: KeyboardEvent): void {
    event.preventDefault();
    const count = this.matches().length;
    if (count === 0) return;
    this.activeIndex.update((index) => (index + step + count) % count);
  }

  private takeActive(event: KeyboardEvent): void {
    event.preventDefault();
    const option = this.matches()[this.activeIndex()];
    if (option) this.choose(option);
  }
}
