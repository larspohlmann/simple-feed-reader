import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { anEntry } from '../../../../testing/entry-testing';
import { ChapterDto } from '../../models';
import { ArticleSource } from './article-source.service';
import { EntryBodyService, EntryBodyState } from './entry-body.service';
import { ReaderContentService } from './reader-content.service';
import { ReaderModeService } from './reader-mode.service';

describe('ArticleSource chapters', () => {
  const chapters: ChapterDto[] = [{ startSeconds: 30, title: 'Intro' }];

  function sourceWith(body: EntryBodyState): ArticleSource {
    TestBed.configureTestingModule({
      providers: [
        ArticleSource,
        { provide: ReaderContentService, useValue: {} },
        { provide: ReaderModeService, useValue: { mode: () => 'original' } },
        { provide: EntryBodyService, useValue: { body: () => signal(body) } },
      ],
    });
    const source = TestBed.inject(ArticleSource);
    source.connect(signal(anEntry({ id: 1 })));
    return source;
  }

  it('serves the chapters of a loaded body', () => {
    expect(sourceWith({ status: 'ok', html: '<p>x</p>', chapters }).chapters()).toEqual(chapters);
  });

  it('serves none while the body loads', () => {
    expect(sourceWith({ status: 'loading' }).chapters()).toEqual([]);
  });
});
