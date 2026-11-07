import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal, viewChild } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { Observable, of, switchMap } from 'rxjs';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TagPickerComponent } from '../tag-picker/tag-picker.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { ToggleComponent } from '../../../shared/toggle/toggle.component';
import { parseProblem } from '../../../core/problem';
import { ReaderApi } from '../../reader-api';
import { TagsStore } from '../../state/tags.store';
import { SubscriptionDto } from '../../models';
import { titleFollowsSource } from '../display-title';
import { isSearchFeed } from '../../state/social-sections';
import { SocialSearchFormComponent } from '../social-picker/social-search-form.component';
import { SocialSearchService } from '../social-picker/social-search.service';
import { ButtonComponent } from '../../../shared/button/button.component';

export const UNSUBSCRIBE_REQUESTED = 'unsubscribe';
export type EditSubscriptionResult = SubscriptionDto | typeof UNSUBSCRIBE_REQUESTED;

@Component({
  selector: 'app-edit-subscription-dialog',
  imports: [
    ReactiveFormsModule,
    A11yModule,
    IconComponent,
    TagPickerComponent,
    FieldComponent,
    ButtonComponent,
    OverlayPanelComponent,
    ToggleComponent,
    SocialSearchFormComponent,
    TranslocoPipe,
  ],
  templateUrl: './edit-subscription-dialog.component.html',
  styleUrl: './edit-subscription-dialog.component.scss',
})
export class EditSubscriptionDialogComponent implements OnInit {
  readonly ref = inject<DialogRef<EditSubscriptionResult>>(DialogRef);
  readonly data = inject<SubscriptionDto>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  private readonly socialSearch = inject(SocialSearchService);
  readonly tagsStore = inject(TagsStore);
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly prefilledTitle = this.data.title;

  // Prefilled with the title as it reads now, so renaming is an edit rather
  // than a retype. Saving it unchanged does pin the current name against a
  // later feed rename -- which is what the reset below is for.
  readonly form = this.fb.group({
    customTitle: [this.prefilledTitle, [Validators.maxLength(512)]],
  });
  readonly checked = signal<Set<number>>(new Set(this.data.tags.map((tag) => tag.id)));
  readonly includeInAllItems = signal<boolean>(this.data.includeInAllItems);
  readonly includeInForYou = signal<boolean>(this.data.includeInForYou);
  readonly includeInSavedSearches = signal<boolean>(this.data.includeInSavedSearches);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly search = isSearchFeed(this.data) ? this.data.social : null;
  private readonly queryForm = viewChild(SocialSearchFormComponent);
  private readonly newQuery = computed(() => this.queryForm()?.normalizedQuery() ?? '');
  readonly blankQuery = computed(() => this.search !== null && this.newQuery() === '');

  ngOnInit(): void {
    if (this.tagsStore.tags().length === 0) this.tagsStore.load();
  }

  /** Drop the override, so the feed's own title shows through again. An empty
   *  field is what clears it server-side. */
  resetTitle(): void {
    this.form.controls.customTitle.setValue('');
  }

  /** Left as prefilled, a title that follows its source stays unset. */
  private submittedTitle(): string | null {
    const title = this.form.getRawValue().customTitle.trim();
    if (titleFollowsSource(this.data) && title === this.prefilledTitle) return null;
    return title || null;
  }

  requestUnsubscribe(): void {
    this.ref.close(UNSUBSCRIBE_REQUESTED);
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
    if (this.form.invalid || this.blankQuery()) return;
    const body = {
      customTitle: this.submittedTitle(),
      tagIds: [...this.checked()],
      includeInAllItems: this.includeInAllItems(),
      includeInForYou: this.includeInForYou(),
      includeInSavedSearches: this.includeInSavedSearches(),
    };
    this.loading.set(true);
    this.error.set(null);
    this.requeried()
      .pipe(switchMap(() => this.api.updateSubscription(this.data.id, body)))
      .subscribe({
        next: (response) => this.ref.close(response.subscription),
        error: (error: HttpErrorResponse) => {
          this.loading.set(false);
          const problem = parseProblem(error);
          const fieldError = problem.errors?.['customTitle']?.[0] ?? problem.errors?.['query']?.[0];
          this.error.set(fieldError ?? problem.detail ?? problem.title);
        },
      });
  }

  /** Saved first: its 409 or 422 must stop the rest of the save. */
  private requeried(): Observable<unknown> {
    const query = this.newQuery();
    if (this.search === null || query === this.search.query) return of(null);
    return this.socialSearch.changeQuery(this.data.id, query);
  }
}
