import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { Observable, Subject, of } from 'rxjs';
import { SentPost } from './composed-post';
import { ComposeRequest } from './compose-request';
import { Composer } from './composer.service';
import { ComposerDialogComponent } from './composer-dialog.component';
import { PostSentDialogComponent } from './post-sent-dialog.component';

describe('Composer', () => {
  const request: ComposeRequest = { kind: 'reply', entryId: 7, network: 'mastodon', address: null };
  const sent: SentPost = {
    address: 'https://x/9',
    url: null,
    kind: 'reply',
    network: 'mastodon',
    text: 'Hi',
    authorHandle: '@a',
    subject: null,
  };
  let closed: Observable<SentPost[] | undefined>;
  let dialog: { open: jest.Mock };
  let composer: Composer;

  beforeEach(() => {
    closed = of([sent]);
    dialog = { open: jest.fn(() => ({ closed })) };
    TestBed.configureTestingModule({ providers: [{ provide: Dialog, useValue: dialog }] });
    composer = TestBed.inject(Composer);
  });

  it('opens the composer dialog with the request as its data', () => {
    composer.open(request).subscribe();

    expect(dialog.open).toHaveBeenCalledWith(ComposerDialogComponent, {
      panelClass: 'app-dialog',
      data: request,
    });
  });

  it('emits the posts the composer closed with, once', () => {
    const emitted: SentPost[][] = [];
    const completed = jest.fn();

    composer.open(request).subscribe({ next: (posts) => emitted.push(posts), complete: completed });

    expect(emitted).toEqual([[sent]]);
    expect(completed).toHaveBeenCalled();
  });

  it('emits nothing when the composer closes without posts', () => {
    const closing = new Subject<SentPost[] | undefined>();
    closed = closing;
    const emitted: SentPost[][] = [];
    const completed = jest.fn();
    composer.open(request).subscribe({ next: (posts) => emitted.push(posts), complete: completed });

    closing.next(undefined);
    closing.complete();

    expect(emitted).toEqual([]);
    expect(completed).toHaveBeenCalled();
  });

  it('announces posts in the sent dialog', () => {
    composer.announce([sent]);

    expect(dialog.open).toHaveBeenCalledWith(PostSentDialogComponent, {
      panelClass: 'app-dialog',
      data: [sent],
    });
  });
});
