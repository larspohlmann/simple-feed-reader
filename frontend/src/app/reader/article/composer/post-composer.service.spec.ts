import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { ComposerDialogComponent, ComposeTarget } from './composer-dialog.component';
import { PostComposer } from './post-composer.service';

describe('PostComposer', () => {
  it('opens the composer as an app dialog and answers what it closed with', () => {
    const published = { address: 'at://x', url: null };
    const dialog = { open: jest.fn(() => ({ closed: of(published) })) };
    TestBed.configureTestingModule({ providers: [{ provide: Dialog, useValue: dialog }] });
    const target: ComposeTarget = {
      kind: 'reply',
      entryId: 7,
      network: 'bluesky',
      address: null,
      authorName: null,
      authorHandle: '@ann.bsky.social',
      excerpt: 'Hi',
      card: null,
    };
    let received: unknown;

    TestBed.inject(PostComposer)
      .open(target)
      .subscribe((reply) => (received = reply));

    expect(dialog.open).toHaveBeenCalledWith(ComposerDialogComponent, {
      panelClass: 'app-dialog',
      data: target,
    });
    expect(received).toBe(published);
  });
});
