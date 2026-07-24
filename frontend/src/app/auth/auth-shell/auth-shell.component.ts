// src/app/auth/auth-shell/auth-shell.component.ts
import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-auth-shell',
  templateUrl: './auth-shell.component.html',
  styleUrl: './auth-shell.component.scss',
})
export class AuthShellComponent {
  @Input({ required: true }) title!: string;
  @Input() subtitle: string | null = null;
}
