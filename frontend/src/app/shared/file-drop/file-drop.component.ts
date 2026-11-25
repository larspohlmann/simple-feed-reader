import { ChangeDetectionStrategy, Component, input, output, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../button/button.component';
import { IconComponent } from '../icon/icon.component';
import { matchesAccept } from './matches-accept';

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

  protected readonly over = signal(false);
  protected readonly rejected = signal(false);

  protected onDragEnter(event: DragEvent): void {
    event.preventDefault();
    if (!this.disabled()) this.over.set(true);
  }

  protected onDragOver(event: DragEvent): void {
    event.preventDefault();
  }

  protected onDragLeave(): void {
    this.over.set(false);
  }

  protected onDrop(event: DragEvent): void {
    event.preventDefault();
    this.over.set(false);
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
