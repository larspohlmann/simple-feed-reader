import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
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
import { LabelsStore } from './labels.store';
import { LABEL_NAME_MAX, LabelTally } from './labels.models';
import { LabelledRow, toLabelPill } from './labelled-row';

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
  readonly row = input.required<LabelledRow>();

  private readonly toast = inject(ToastService);
  private readonly injector = inject(Injector);
  private readonly labels = inject(LabelsStore).labels;
  private readonly nameField = viewChild<ElementRef<HTMLInputElement>>('nameField');
  private readonly newLabelItem = viewChild<ElementRef<HTMLButtonElement>>('newLabelItem');

  protected readonly nameMax = LABEL_NAME_MAX;
  protected readonly checkedIds = computed(() => {
    const row = this.row();
    return new Set(
      this.labels()
        .filter((label) => row.carries(label.id))
        .map((label) => label.id),
    );
  });
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
    this.row().toggle(toLabelPill(label));
  }

  protected create(name: string): void {
    const trimmed = name.trim();
    if (trimmed === '' || this.saving()) return;
    this.saving.set(true);
    this.row()
      .createLabel(trimmed)
      .subscribe({
        complete: () => {
          this.saving.set(false);
          this.naming.set(false);
          afterNextRender(() => this.newLabelItem()?.nativeElement.focus(), {
            injector: this.injector,
          });
        },
        error: (problem: Problem) => {
          this.saving.set(false);
          this.toast.showProblem(problem);
        },
      });
  }
}
