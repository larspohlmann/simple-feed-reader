import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { ReplyComposerDialogComponent, ReplyTarget } from './reply-composer-dialog.component';
import { ReplyComposer } from './reply-composer.service';

describe('ReplyComposer', () => {
  it('opens the composer as an app dialog and answers what it closed with', () => {
    const published = { address: 'at://x', url: null };
    const dialog = { open: jest.fn(() => ({ closed: of(published) })) };
    TestBed.configureTestingModule({ providers: [{ provide: Dialog, useValue: dialog }] });
    const target: ReplyTarget = {
      entryId: 7,
      network: 'bluesky',
      address: null,
      authorName: null,
      authorHandle: '@ann.bsky.social',
      excerpt: 'Hi',
    };
    let received: unknown;

    TestBed.inject(ReplyComposer)
      .open(target)
      .subscribe((reply) => (received = reply));

    expect(dialog.open).toHaveBeenCalledWith(ReplyComposerDialogComponent, {
      panelClass: 'app-dialog',
      data: target,
    });
    expect(received).toBe(published);
  });
});
