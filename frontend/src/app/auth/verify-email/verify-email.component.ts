import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthApi } from '../auth-api';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';

@Component({
  selector: 'app-verify-email',
  imports: [RouterLink, TranslocoPipe, AuthShellComponent, SpinnerComponent],
  templateUrl: './verify-email.component.html',
  styleUrl: './verify-email.component.scss',
})
export class VerifyEmailComponent implements OnInit {
  private readonly authApi = inject(AuthApi);
  private readonly route = inject(ActivatedRoute);
  readonly state = signal<'loading' | 'ok' | 'error'>('loading');

  ngOnInit(): void {
    this.route.queryParamMap.subscribe((params) => {
      const token = params.get('token');
      if (!token) {
        this.state.set('error');
        return;
      }
      this.authApi.verifyEmail(token).subscribe({
        next: () => this.state.set('ok'),
        error: () => this.state.set('error'),
      });
    });
  }
}
