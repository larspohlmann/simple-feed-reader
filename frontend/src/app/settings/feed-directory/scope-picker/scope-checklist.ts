import { Signal, WritableSignal, computed, linkedSignal, signal } from '@angular/core';
import { toggled } from '../../../core/toggled-set';
import { ScopeOption } from './scope-options';

export interface ScopeChecklistKeys {
  readonly id: 'countries' | 'languages';
  readonly heading: string;
  readonly filter: string;
}

export class ScopeChecklist {
  readonly filter = signal('');
  readonly picked: WritableSignal<ReadonlySet<string>>;

  readonly visible = computed(() => {
    const needle = this.filter().trim().toLocaleLowerCase();
    if (needle === '') return this.options();

    return this.options().filter((option) => option.name.toLocaleLowerCase().includes(needle));
  });

  readonly chips = computed<ScopeOption[]>(() => {
    const byCode = new Map(this.options().map((option) => [option.code, option]));

    return [...this.picked()].map((code) => byCode.get(code) ?? { code, name: code, sites: 0 });
  });

  readonly hasPick = computed(() => this.picked().size > 0);

  constructor(
    readonly keys: ScopeChecklistKeys,
    private readonly options: Signal<readonly ScopeOption[]>,
    initialPicks: () => readonly string[],
  ) {
    this.picked = linkedSignal<ReadonlySet<string>>(() => new Set(initialPicks()));
  }

  isPicked(code: string): boolean {
    return this.picked().has(code);
  }

  toggle(code: string): void {
    this.picked.update((picked) => toggled(picked, code));
  }

  codes(): string[] {
    return [...this.picked()];
  }
}
