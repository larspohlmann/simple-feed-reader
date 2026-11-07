import {
  ChangeDetectionStrategy,
  Component,
  afterNextRender,
  inject,
  input,
  output,
  viewChild,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { SocialNetworkName } from '../../../core/social-network';
import { ButtonComponent } from '../../../shared/button/button.component';
import { SocialImportService } from './social-import.service';
import { SocialSearchFormComponent } from './social-search-form.component';

@Component({
  selector: 'app-social-search-tab',
  imports: [ButtonComponent, SocialSearchFormComponent, TranslocoPipe],
  templateUrl: './social-search-tab.component.html',
  styleUrl: './social-search-tab.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialSearchTabComponent {
  readonly network = input.required<SocialNetworkName>();
  readonly accountId = input.required<number>();
  readonly subscribe = output<string>();

  protected readonly importer = inject(SocialImportService);
  private readonly form = viewChild.required(SocialSearchFormComponent);

  constructor() {
    afterNextRender(() => this.form().focus());
  }
}
