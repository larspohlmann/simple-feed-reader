import { Injectable, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { LinkCardDto, signedUrlOf } from '../link-card/link-card';
import { linkParam } from '../query/link-param';

@Injectable()
export class LinkReaderNavigation {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly openedCard = signal<LinkCardDto | null>(null);

  /** A comment's card is in no store, so its title and image are known only from the click. */
  readonly opened = this.openedCard.asReadonly();

  layer(card: LinkCardDto): void {
    this.openedCard.set(card);
    this.navigate({ link: linkParam(signedUrlOf(card)) });
  }

  openAlone(card: LinkCardDto): void {
    this.openedCard.set(card);
    this.navigate({ link: linkParam(signedUrlOf(card)), entry: null });
  }

  close(): void {
    this.navigate({ link: null });
  }

  private navigate(queryParams: Record<string, string | null>): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams,
      queryParamsHandling: 'merge',
    });
  }
}
