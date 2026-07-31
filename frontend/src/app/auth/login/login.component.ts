// src/app/auth/login/login.component.ts
import { Component, ElementRef, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { LanguageService } from '../../core/language.service';
import { parseProblem } from '../../core/problem';
import { adoptAutofilledValues } from '../autofill';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';
import { FieldComponent } from '../../shared/field/field.component';

@Component({
  selector: 'app-login',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslocoPipe,
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
    FieldComponent,
  ],
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss',
})
export class LoginComponent implements OnInit {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly auth = inject(AuthService);
  private readonly language = inject(LanguageService);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslocoService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly providers = signal<string[]>([]);

  ngOnInit(): void {
    this.http.get<{ providers: string[] }>(`${this.base}/api/auth/oauth/providers`).subscribe({
      next: (r) => this.providers.set(r.providers ?? []),
      error: () => this.providers.set([]),
    });
  }

  submit(): void {
    if (this.loading()) return;
    // This form already carried a hand-rolled version of this, keyed on the
    // `name` attribute and querying the whole document. It is now the shared
    // helper: scoped to this component, and keyed on formControlName, which is
    // the attribute actually guaranteed to be there. Generalising it is the
    // point -- the register form had the identical bug and no workaround, which
    // is how a filled-in form came to refuse to submit with nothing on screen.
    adoptAutofilledValues(this.host.nativeElement, this.form);

    if (this.form.invalid) {
      this.error.set(this.i18n.translate('auth.login.invalidInput'));
      return;
    }
    this.loading.set(true);
    this.error.set(null);
    const { email, password } = this.form.getRawValue();
    this.auth.login(email, password).subscribe({
      next: () =>
        this.auth.loadMe().subscribe({
          next: (user) => {
            this.language.adopt(user.locale);
            void this.router.navigate(['/']);
          },
          error: () => void this.router.navigate(['/']),
        }),
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e).detail ?? this.i18n.translate('auth.login.failed'));
        this.loading.set(false);
      },
    });
  }

  oauthUrl(provider: string): string {
    return `${this.base}/api/auth/oauth/${provider}`;
  }

  startOAuth(provider: string): void {
    location.assign(this.oauthUrl(provider));
  }

  label(provider: string): string {
    return provider.charAt(0).toUpperCase() + provider.slice(1);
  }
}
