import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { TranslocoPipe } from '@jsverse/transloco';
import { Problem } from '../../core/problem';
import { IconComponent } from '../../shared/icon/icon.component';
import { MenuCheckboxComponent } from '../../shared/menu-checkbox/menu-checkbox.component';
import { stepMenu } from '../../shared/menu-keys';
import { ToastService } from '../../shared/toast/toast.service';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { EntryDto } from '../models';
import { EntryRowCache } from '../state/entry-row-cache';
import { LabelsStore } from './labels.store';
import { LabelTally } from './labels.models';
import { toLabelPill, toggleLabel } from './label-toggle';

const MOST_LABELS_UNFILTERED = 8;

/** The fine-pointer label menu `LabelMenu` opens at a row's or the article's label button: a box per label that
 *  toggles in place, and a "New label…" item that turns into the field naming one. */
@Component({
  selector: 'app-label-menu-panel',
  imports: [A11yModule, IconComponent, MenuCheckboxComponent, TranslocoPipe],
  templateUrl: './label-menu-panel.component.html',
  styleUrl: './label-menu-panel.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(keydown)': 'step($event)' },
})
export class LabelMenuPanelComponent {
  readonly entry = input.required<EntryDto>();
  readonly actions = input.required<EntryActionHandler>();

  private readonly rows = inject(EntryRowCache);
  private readonly toast = inject(ToastService);
  private readonly labels = inject(LabelsStore).labels;
  private readonly nameField = viewChild<ElementRef<HTMLInputElement>>('nameField');

  private readonly shown = computed(() => this.rows.entry(this.entry().id) ?? this.entry());
  protected readonly checkedIds = computed(
    () => new Set(this.shown().labels.map((label) => label.id)),
  );
  protected readonly filterable = computed(() => this.labels().length > MOST_LABELS_UNFILTERED);
  protected readonly query = signal('');
  protected readonly listed = computed(() => {
    const query = this.filterable() ? this.query().trim().toLocaleLowerCase() : '';
    return this.labels().filter((label) => label.name.toLocaleLowerCase().includes(query));
  });
  protected readonly naming = signal(false);
  protected readonly saving = signal(false);

  constructor() {
    effect(() => this.nameField()?.nativeElement.focus());
  }

  protected step(event: KeyboardEvent): void {
    stepMenu(event);
  }

  protected toggle(label: LabelTally): void {
    toggleLabel(this.actions(), this.shown(), toLabelPill(label));
  }

  protected create(name: string): void {
    const trimmed = name.trim();
    if (trimmed === '' || this.saving()) return;
    this.saving.set(true);
    this.actions()
      .createLabel(this.shown(), trimmed)
      .subscribe({
        complete: () => {
          this.saving.set(false);
          this.naming.set(false);
        },
        error: (problem: Problem) => {
          this.saving.set(false);
          this.toast.showProblem(problem);
        },
      });
  }
}
