import { ChangeDetectionStrategy, Component, computed, input, output, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../button/button.component';
import { IconComponent } from '../icon/icon.component';
import { matchesAccept } from './matches-accept';

let nextId = 0;

@Component({
  selector: 'app-file-drop',
  imports: [ButtonComponent, IconComponent, TranslocoPipe],
  templateUrl: './file-drop.component.html',
  styleUrl: './file-drop.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FileDropComponent {
  readonly accept = input.required<string>();
  readonly label = input.required<string>();
  readonly hint = input<string | null>(null);
  readonly disabled = input(false);
  readonly picked = output<File>();

  protected readonly over = computed(() => this.depth() > 0);
  private readonly depth = signal(0);
  protected readonly rejected = signal(false);
  protected readonly labelId = `file-drop-${nextId}-label`;
  protected readonly hintId = `file-drop-${nextId++}-hint`;
  protected readonly describedBy = computed(() =>
    this.hint() ? `${this.labelId} ${this.hintId}` : this.labelId,
  );

  protected onDragEnter(event: DragEvent): void {
    event.preventDefault();
    if (!this.disabled()) this.depth.update((depth) => depth + 1);
  }

  protected onDragOver(event: DragEvent): void {
    event.preventDefault();
  }

  protected onDragLeave(): void {
    this.depth.update((depth) => Math.max(0, depth - 1));
  }

  protected onDrop(event: DragEvent): void {
    event.preventDefault();
    this.depth.set(0);
    if (this.disabled()) return;
    const files = Array.from(event.dataTransfer?.files ?? []);
    const match = files.find((file) => matchesAccept(file, this.accept()));
    this.rejected.set(match === undefined);
    if (match) this.picked.emit(match);
  }

  protected onChange(input: HTMLInputElement): void {
    const file = input.files?.item(0) ?? null;
    input.value = '';
    if (file === null) return;
    this.rejected.set(false);
    this.picked.emit(file);
  }

  protected openPicker(input: HTMLInputElement): void {
    if (!this.disabled()) input.click();
  }
}
