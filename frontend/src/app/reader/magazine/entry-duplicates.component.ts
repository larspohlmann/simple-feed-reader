import { Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../models';
import { LanguageService } from '../../core/language.service';
import { relativeTime } from '../format';

@Component({
  selector: 'app-entry-duplicates',
  imports: [TranslocoPipe],
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
}
