// src/app/setup/setup.component.ts
import { Component, ElementRef, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../core/auth.service';
import { TokenStore } from '../core/token.store';
import { parseProblem } from '../core/problem';
import { adoptAutofilledValues } from '../auth/autofill';
import { AuthShellComponent } from '../auth/auth-shell/auth-shell.component';
import { ButtonComponent } from '../shared/button/button.component';
import { FormErrorComponent } from '../shared/form-error/form-error.component';
import { FieldComponent } from '../shared/field/field.component';
import { SetupApi } from './setup-api';
import { SetupService } from './setup.service';

@Component({
  selector: 'app-setup',
  imports: [
    ReactiveFormsModule,
    TranslocoPipe,
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
    FieldComponent,
  ],
  templateUrl: './setup.component.html',
  styleUrl: './setup.component.scss',
})
export class SetupComponent {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly api = inject(SetupApi);
  private readonly setup = inject(SetupService);
  private readonly auth = inject(AuthService);
  private readonly tokens = inject(TokenStore);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslocoService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(12)]],
    secret: ['', [Validators.required]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    if (this.loading()) return;
    adoptAutofilledValues(this.host.nativeElement, this.form);

    if (this.form.invalid) {
      this.error.set(this.i18n.translate('setup.invalidInput'));
      return;
    }
    this.loading.set(true);
    this.error.set(null);
    const { email, password, secret } = this.form.getRawValue();
    this.api.createAdmin(email, password, secret).subscribe({
      next: (res) => {
        this.tokens.set(res.token);
        this.setup.markComplete();
        this.auth.loadMe().subscribe({
          next: () => void this.router.navigate(['/']),
          error: () => void this.router.navigate(['/']),
        });
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e).detail ?? this.i18n.translate('setup.failed'));
        this.loading.set(false);
      },
    });
  }
}
