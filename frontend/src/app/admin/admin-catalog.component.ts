// src/app/admin/admin-catalog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, ElementRef, OnInit, computed, inject, signal, viewChild } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { IconComponent } from '../shared/icon/icon.component';
import { SpinnerComponent } from '../shared/spinner/spinner.component';
import { FieldComponent } from '../shared/field/field.component';
import { ButtonComponent } from '../shared/button/button.component';
import { ConfirmData, ConfirmDialogComponent } from '../reader/manage/confirm-dialog.component';
import { AdminApi } from './admin-api';
import { CategoryFormDialogComponent } from './category-form-dialog.component';
import { FeedFormDialogComponent, FeedFormData } from './feed-form-dialog.component';
import {
  AdminCatalogCategoryDto,
  AdminCatalogFeedDto,
  BundledCatalogInfo,
  CatalogImportCounts,
  CatalogWarmReport,
  ImportMode,
} from './admin.models';

@Component({
  selector: 'app-admin-catalog',
  imports: [
    FieldComponent,
    ButtonComponent,
    ErrorBannerComponent,
    IconComponent,
    SpinnerComponent,
    TranslocoPipe,
  ],
  templateUrl: './admin-catalog.component.html',
  styleUrl: './admin-catalog.component.scss',
})
export class AdminCatalogComponent implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly dialog = inject(Dialog);
  private readonly i18n = inject(TranslocoService);

  readonly categories = signal<AdminCatalogCategoryDto[]>([]);
  readonly feeds = signal<AdminCatalogFeedDto[]>([]);
  readonly bundled = signal<BundledCatalogInfo | null>(null);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);
  // A failed row action leaves the loaded list in place, unlike a list-load error.
  readonly actionError = signal<Problem | null>(null);

  readonly importMode = signal<ImportMode>('merge');
  readonly pendingDocument = signal<string | null>(null);
  readonly importCounts = signal<CatalogImportCounts | null>(null);
  readonly importError = signal<Problem | null>(null);

  readonly warming = signal(false);
  readonly warmRemaining = signal(0);
  readonly warmReport = signal<CatalogWarmReport | null>(null);

  readonly hasFeeds = computed(() => this.feeds().length > 0);

  private readonly fileInput = viewChild<ElementRef<HTMLInputElement>>('fileInput');

  ngOnInit(): void {
    this.load();
    this.loadBundled();
  }

  load(): void {
    this.fetchCatalog();
  }

  private fetchCatalog(afterLoad?: () => void): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.catalog().subscribe({
      next: (result) => {
        this.categories.set(result.categories);
        this.feeds.set(result.feeds);
        this.loading.set(false);
        afterLoad?.();
      },
      error: (failure: HttpErrorResponse) => {
        this.error.set(parseProblem(failure));
        this.loading.set(false);
      },
    });
  }

  private loadBundled(): void {
    this.api.bundledCatalog().subscribe({
      next: (info) => this.bundled.set(info),
      error: () => this.bundled.set(null),
    });
  }

  feedsFor(categoryId: number): AdminCatalogFeedDto[] {
    return this.feeds()
      .filter((feed) => feed.categoryId === categoryId)
      .sort((left, right) => left.position - right.position);
  }

  // --- Dialog-based editing ---------------------------------------------------

  openCategoryDialog(category: AdminCatalogCategoryDto | null): void {
    const ref = this.dialog.open<AdminCatalogCategoryDto>(CategoryFormDialogComponent, {
      data: category,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((saved) => {
      if (saved) this.upsertCategory(saved);
    });
  }

  openFeedDialog(feed: AdminCatalogFeedDto | null, categoryId: number): void {
    const data: FeedFormData = { feed, categories: this.categories(), categoryId };
    const ref = this.dialog.open<AdminCatalogFeedDto>(FeedFormDialogComponent, {
      data,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((saved) => {
      if (saved) this.upsertFeed(saved);
    });
  }

  confirmDeleteCategory(category: AdminCatalogCategoryDto): void {
    const data: ConfirmData = {
      title: this.i18n.translate('admin.confirmDelete.categoryTitle'),
      message: this.i18n.translate('admin.confirmDelete.categoryMessage', {
        name: category.name,
      }),
      confirmLabel: this.i18n.translate('common.delete'),
      danger: true,
    };
    this.openConfirm(data, () => this.deleteCategory(category));
  }

  confirmDeleteFeed(feed: AdminCatalogFeedDto): void {
    const data: ConfirmData = {
      title: this.i18n.translate('admin.confirmDelete.feedTitle'),
      message: this.i18n.translate('admin.confirmDelete.feedMessage', { title: feed.title }),
      confirmLabel: this.i18n.translate('common.delete'),
      danger: true,
    };
    this.openConfirm(data, () => this.deleteFeed(feed));
  }

  private openConfirm(data: ConfirmData, onConfirm: () => void): void {
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      // A destructive confirmation is an alert, not a plain dialog.
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) onConfirm();
    });
  }

  // --- Category actions -------------------------------------------------------

  deleteCategory(category: AdminCatalogCategoryDto): void {
    this.actionError.set(null);
    this.api.deleteCategory(category.id).subscribe({
      next: () => {
        this.categories.update((list) => list.filter((entry) => entry.id !== category.id));
        this.feeds.update((list) => list.filter((feed) => feed.categoryId !== category.id));
      },
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  moveCategory(index: number, delta: number): void {
    const reordered = this.swap(this.categories(), index, index + delta);
    if (reordered === null) return;
    this.categories.set(reordered);
    this.actionError.set(null);
    this.api.reorderCategories(reordered.map((entry) => entry.id)).subscribe({
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  // --- Feed actions -------------------------------------------------------------

  deleteFeed(feed: AdminCatalogFeedDto): void {
    this.actionError.set(null);
    this.api.deleteFeed(feed.id).subscribe({
      next: () => this.feeds.update((list) => list.filter((entry) => entry.id !== feed.id)),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  moveFeed(feed: AdminCatalogFeedDto, delta: number): void {
    const siblings = this.feedsFor(feed.categoryId);
    const swapped = this.swap(siblings, siblings.indexOf(feed), siblings.indexOf(feed) + delta);
    if (swapped === null) return;
    // Reassign positions to the new indices so feedsFor's position sort agrees
    // with the visible order — and matches what reorderFeeds persists (position
    // by list index across the ids we send, which are exactly this category's).
    const reordered = swapped.map((entry, index) => ({ ...entry, position: index }));
    const others = this.feeds().filter((entry) => entry.categoryId !== feed.categoryId);
    this.feeds.set([...others, ...reordered]);
    this.actionError.set(null);
    this.api.reorderFeeds(reordered.map((entry) => entry.id)).subscribe({
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  refreshFavicon(feed: AdminCatalogFeedDto): void {
    this.actionError.set(null);
    this.api.refreshFavicon(feed.id).subscribe({
      next: (result) => this.upsertFeed(result.feed),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  // --- Import ---------------------------------------------------------------

  setMode(event: Event): void {
    this.importMode.set((event.target as HTMLSelectElement).value as ImportMode);
  }

  async onFileSelected(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    this.pendingDocument.set(await file.text());
  }

  importBundled(): void {
    this.beginImport(this.api.importBundledCatalog(this.importMode()));
  }

  importUpload(): void {
    const document = this.pendingDocument();
    if (document === null) return;
    this.beginImport(this.api.importCatalog(this.importMode(), document));
  }

  private beginImport(request: ReturnType<AdminApi['importCatalog']>): void {
    this.importError.set(null);
    this.importCounts.set(null);
    request.subscribe({
      next: (counts) => this.handleImportSuccess(counts),
      error: (failure: HttpErrorResponse) => this.importError.set(parseProblem(failure)),
    });
  }

  // A fresh import leaves the lists stale and (when it added or changed feeds)
  // with no icons, so we refetch and then warm — that warm loop is the only
  // thing that makes favicons appear on a new deployment.
  private handleImportSuccess(counts: CatalogImportCounts): void {
    this.importCounts.set(counts);
    this.clearPendingDocument();
    const broughtNewFeeds = counts.feedsCreated + counts.feedsUpdated > 0;
    this.fetchCatalog(() => {
      if (broughtNewFeeds) this.warm();
    });
  }

  // Drop the consumed document so a second click cannot resend a stale upload,
  // and clear the native input so the same file can be picked again.
  private clearPendingDocument(): void {
    this.pendingDocument.set(null);
    const input = this.fileInput()?.nativeElement;
    if (input) input.value = '';
  }

  // --- Icon warming ---------------------------------------------------------

  warm(): void {
    this.warming.set(true);
    this.warmReport.set(null);
    this.warmRemaining.set(0);
    this.warmNextSlice();
  }

  private warmNextSlice(): void {
    this.api.warmFavicons().subscribe({
      next: (report) => {
        this.warmRemaining.set(report.remaining);
        const madeNoProgress = report.warmed === 0 && report.failed === 0;
        if (report.remaining === 0 || madeNoProgress) {
          this.warmReport.set(report);
          this.warming.set(false);
          return;
        }
        this.warmNextSlice();
      },
      error: () => this.warming.set(false),
    });
  }

  // --- Helpers --------------------------------------------------------------

  private upsertCategory(category: AdminCatalogCategoryDto): void {
    this.categories.update((list) => {
      const known = list.some((entry) => entry.id === category.id);
      return known
        ? list.map((entry) => (entry.id === category.id ? category : entry))
        : [...list, category];
    });
  }

  private upsertFeed(feed: AdminCatalogFeedDto): void {
    this.feeds.update((list) => {
      const known = list.some((entry) => entry.id === feed.id);
      return known ? list.map((entry) => (entry.id === feed.id ? feed : entry)) : [...list, feed];
    });
  }

  private swap<T>(list: T[], from: number, to: number): T[] | null {
    if (to < 0 || to >= list.length) return null;
    const copy = [...list];
    [copy[from], copy[to]] = [copy[to], copy[from]];
    return copy;
  }
}
