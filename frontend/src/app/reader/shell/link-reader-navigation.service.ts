import { Injectable, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { LinkCardRef, linkParam } from '../query/link-param';

@Injectable()
export class LinkReaderNavigation {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  layer(ref: LinkCardRef): void {
    this.navigate({ link: linkParam(ref) });
  }

  openAlone(ref: LinkCardRef): void {
    this.navigate({ link: linkParam(ref), entry: null });
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
