import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { inputValue } from '../../shared/input-value';
import { ConnectionChoice, connectionLabel, unusableConnectionId } from '../connection-choice';

/** Picks the connection a job runs on: the active one (null), a candidate, or, shown but never offered, a stored
 *  pick that can no longer do the job. */
@Component({
  selector: 'app-connection-select',
  templateUrl: './connection-select.component.html',
  styleUrl: './connection-select.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConnectionSelectComponent {
  readonly chosenId = input.required<number | null>();
  readonly candidates = input.required<readonly ConnectionChoice[]>();
  readonly activeLabel = input.required<string>();
  readonly unusableLabel = input.required<string>();
  readonly disabled = input(false);
  readonly chosen = output<number | null>();

  protected readonly unusableId = computed(() =>
    unusableConnectionId(this.chosenId(), this.candidates()),
  );
  protected readonly label = connectionLabel;

  protected onChange(event: Event): void {
    const raw = inputValue(event);
    this.chosen.emit(raw === '' ? null : +raw);
  }
}
