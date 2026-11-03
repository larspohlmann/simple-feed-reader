import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SharingService } from '../../../core/preferences/sharing.service';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryStateActions } from '../../shell/entry-state-actions.service';
import { ArticleSource } from '../content/article-source.service';
import { EntryShareRecorder } from './entry-share-recorder';

describe('EntryShareRecorder', () => {
  const share = jest.fn();
  const rememberShares = signal(true);
  const entry = signal(anEntry({ id: 3, isShared: false }));

  function recorder(): EntryShareRecorder {
    TestBed.configureTestingModule({
      providers: [
        EntryShareRecorder,
        { provide: EntryStateActions, useValue: { share } },
        { provide: SharingService, useValue: { rememberShares } },
        { provide: ArticleSource, useValue: { entry } },
      ],
    });
    return TestBed.inject(EntryShareRecorder);
  }

  beforeEach(() => {
    share.mockReset();
    rememberShares.set(true);
  });

  it('shares the open entry', () => {
    recorder().record();

    expect(share).toHaveBeenCalledWith(entry());
  });

  it('records nothing while remembering is off', () => {
    rememberShares.set(false);

    recorder().record();

    expect(share).not.toHaveBeenCalled();
  });
});
