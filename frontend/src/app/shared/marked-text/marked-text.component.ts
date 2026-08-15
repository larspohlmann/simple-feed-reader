// src/app/shared/marked-text/marked-text.component.ts
import { Component, computed, input } from '@angular/core';
import { markTerms } from '../../reader/search-marks';

@Component({
  selector: 'app-marked-text',
  imports: [],
  templateUrl: './marked-text.component.html',
  styleUrl: './marked-text.component.scss',
})
export class MarkedTextComponent {
  readonly text = input('');
  readonly terms = input<string[]>([]);
  readonly segments = computed(() => markTerms(this.text(), this.terms()));
}
