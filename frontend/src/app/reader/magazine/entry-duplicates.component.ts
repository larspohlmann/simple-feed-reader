import { Component, computed, inject, input, output, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../models';
import { LanguageService } from '../../core/language.service';
import { relativeTime } from '../format';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { EntryRowComponent } from '../entry-row/entry-row.component';

@Component({
  selector: 'app-entry-duplicates',
  imports: [TranslocoPipe, DismissOnOutsideDirective, EntryRowComponent],
  templateUrl: './entry-duplicates.component.html',
  styleUrl: './entry-duplicates.component.scss',
})
export class EntryDuplicatesComponent {
  readonly entry = input.required<EntryDto>();
  readonly open = output<EntryDto>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();

  private readonly language = inject(LanguageService);
  readonly copies = computed(() => this.entry().duplicates ?? []);
  readonly when = (copy: EntryDto): string =>
    relativeTime(copy.publishedAt ?? copy.createdAt, this.language.lang());

  readonly selected = signal<EntryDto | null>(null);
  protected readonly panelTop = signal(0);
  protected readonly panelLeft = signal(0);

  toggle(copy: EntryDto, event: Event): void {
    const trigger = event.currentTarget as HTMLElement;
    if (this.selected() === copy) {
      this.close();
      return;
    }
    this.positionAgainst(trigger);
    this.selected.set(copy);
  }

  close(): void {
    this.selected.set(null);
  }

  private positionAgainst(trigger: HTMLElement): void {
    const gap = 6;
    const rect = trigger.getBoundingClientRect();
    this.panelTop.set(Math.round(rect.bottom + gap));
    this.panelLeft.set(Math.round(rect.left));
  }
}
