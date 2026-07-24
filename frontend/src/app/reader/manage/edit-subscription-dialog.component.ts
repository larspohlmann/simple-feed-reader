// src/app/reader/manage/edit-subscription-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { TagsStore } from '../tags.store';
import { SubscriptionDto } from '../models';

@Component({
  selector: 'app-edit-subscription-dialog',
  imports: [ReactiveFormsModule, A11yModule, IconComponent, TranslocoPipe],
  templateUrl: './edit-subscription-dialog.component.html',
  styleUrl: './edit-subscription-dialog.component.scss',
})
export class EditSubscriptionDialogComponent implements OnInit {
  readonly ref = inject<DialogRef<SubscriptionDto>>(DialogRef);
  readonly data = inject<SubscriptionDto>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  readonly tagsStore = inject(TagsStore);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly form = this.fb.group({
    customTitle: [this.data.customTitle ?? '', [Validators.maxLength(512)]],
  });
  readonly checked = signal<Set<number>>(new Set(this.data.tags.map((t) => t.id)));
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  ngOnInit(): void {
    if (this.tagsStore.tags().length === 0) this.tagsStore.load();
  }

  toggle(id: number): void {
    this.checked.update((set) => {
      const next = new Set(set);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }

  submit(): void {
    if (this.form.invalid) return;
    const body = {
      customTitle: this.form.getRawValue().customTitle.trim() || null,
      tagIds: [...this.checked()],
    };
    this.loading.set(true);
    this.error.set(null);
    this.api.updateSubscription(this.data.id, body).subscribe({
      next: (r) => this.ref.close(r.subscription),
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        const p = parseProblem(e);
        this.error.set(p.errors?.['customTitle']?.[0] ?? p.detail ?? p.title);
      },
    });
  }
}
