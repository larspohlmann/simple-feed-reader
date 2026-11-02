import { Injectable } from '@angular/core';
import { Observable, Subject } from 'rxjs';
import { SocialNetworkName } from '../core/social-network';

/** An entry the server removed because its post was deleted on its network (#1975). */
export interface RemovedPost {
  id: number;
  network: SocialNetworkName;
}

/** Where the article's social row reports a removed post to the shell that owns the list and the route. */
@Injectable({ providedIn: 'root' })
export class RemovedPosts {
  private readonly removed = new Subject<RemovedPost>();
  readonly removed$: Observable<RemovedPost> = this.removed.asObservable();

  announce(post: RemovedPost): void {
    this.removed.next(post);
  }
}
