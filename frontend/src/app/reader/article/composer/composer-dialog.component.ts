import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  linkedSignal,
  signal,
  viewChild,
} from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { inputValue } from '../../../shared/input-value';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import {
  ComposerContextDto,
  ComposerKind,
  MASTODON_VISIBILITIES,
  MastodonVisibility,
  PostTargetDto,
} from '../../entry-social';
import { SentPost } from './composed-post';
import { SharedDraft } from './composer-lane';
import { ComposerLanes } from './composer-lanes';
import { ComposerSubjectComponent } from './composer-subject.component';
import { postLength } from './post-length';

/**
 * Writes a reply to a Lemmy post or comment, a reply to or a quote of a Bluesky or Mastodon post, or a post of a shared URL.
 * Closing never asks: typed text is discarded.
 * It stays open while a post is on the wire, so the reader always learns what was published.
 * A failed send keeps the text and says why; a resend carries the key its lane minted, so the
 * network publishes the post once.
 */
@Component({
  selector: 'app-composer-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ComposerSubjectComponent,
    ErrorBannerComponent,
    FieldComponent,
    OverlayPanelComponent,
    SkeletonComponent,
    TranslocoPipe,
  ],
  templateUrl: './composer-dialog.component.html',
  styleUrl: './composer-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [ComposerLanes],
})
export class ComposerDialogComponent {
  protected readonly ref = inject<DialogRef<SentPost[]>>(DialogRef);
  protected readonly target = inject<PostTargetDto>(DIALOG_DATA);
  private readonly lanes = inject(ComposerLanes);
  private readonly textField = viewChild<ElementRef<HTMLTextAreaElement>>('textField');

  protected readonly visibilities = MASTODON_VISIBILITIES;
  protected readonly primary = this.lanes.primary;
  private textTouched = false;
  protected readonly text = linkedSignal<string | undefined, string>({
    source: () => this.primary().context()?.text,
    computation: (starting, previous) =>
      this.textTouched || starting === undefined ? (previous?.value ?? '') : starting,
  });
  private readonly caretBeforeText = this.target.kind === 'link' && this.target.quote !== null;
  protected readonly contentWarning = signal<string | null>(null);
  protected readonly sending = signal(false);

  protected readonly context = computed(() => this.primary().context());
  private readonly kind = linkedSignal<ComposerContextDto | null, ComposerKind>({
    source: this.context,
    computation: (context, previous) => context?.kind ?? previous?.value ?? this.target.kind,
  });
  protected readonly forbiddenKey = computed(() => {
    const context = this.context();
    if (context === null || context.canSend || context.kind === 'link') return null;
    return `reader.${context.kind}Forbidden.${this.target.network}`;
  });
  /** Where the words that differ between a reply, a quote and a link post live. */
  protected readonly copy = computed(() => `reader.composer.${this.kind()}`);
  protected readonly headingKey = computed(() => `reader.${this.kind()}.${this.target.network}`);
  protected readonly used = computed(() => postLength(this.text(), this.target.network));
  protected readonly over = computed(() => {
    const max = this.context()?.maxCharacters;
    return max !== undefined && this.used() > max;
  });
  protected readonly canSend = computed(
    () =>
      this.context()?.canSend === true &&
      this.text().trim() !== '' &&
      !this.over() &&
      !this.sending(),
  );

  constructor() {
    this.lanes.init(this.target);
    // The field appears only once the context loads, after the focus trap placed its initial focus.
    effect(() => this.placeCaret(this.textField()?.nativeElement));
  }

  private placeCaret(field: HTMLTextAreaElement | undefined): void {
    if (field === undefined) return;
    field.focus();
    if (this.caretBeforeText && !this.textTouched) field.setSelectionRange(0, 0);
  }

  protected chooseAccount(event: Event): void {
    this.primary().chooseAccount(Number(inputValue(event)));
  }

  protected write(event: Event): void {
    this.textTouched = true;
    this.text.set(inputValue(event));
  }

  protected clear(): void {
    this.textTouched = true;
    this.text.set('');
    this.textField()?.nativeElement.focus();
  }

  protected chooseVisibility(event: Event): void {
    this.primary().chooseVisibility(inputValue(event) as MastodonVisibility);
  }

  protected toggleWarning(event: Event): void {
    this.contentWarning.set((event.target as HTMLInputElement).checked ? '' : null);
  }

  protected writeWarning(event: Event): void {
    this.contentWarning.set(inputValue(event));
  }

  protected send(event: Event): void {
    event.preventDefault();
    if (!this.canSend()) return;
    this.sending.set(true);
    this.ref.disableClose = true;
    this.primary()
      .send(this.sharedDraft(), this.text())
      .subscribe({
        next: (sent) => this.ref.close([sent]),
        error: () => {
          this.sending.set(false);
          this.ref.disableClose = false;
        },
      });
  }

  private sharedDraft(): SharedDraft {
    const warning = this.contentWarning()?.trim() ?? '';
    return {
      text: this.text(),
      language: browserLanguage(),
      ...(warning === '' ? {} : { contentWarning: warning }),
    };
  }
}

function browserLanguage(): string | null {
  const primary = navigator.language.split('-')[0]?.toLowerCase() ?? '';
  return /^[a-z]{2,3}$/.test(primary) ? primary : null;
}
