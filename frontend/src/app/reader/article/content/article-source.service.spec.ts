import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { anEntry } from '../../../../testing/entry-testing';
import { ChapterDto, TranscriptDto } from '../../models';
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
    expect(
      sourceWith({ status: 'ok', html: '<p>x</p>', chapters, transcript: null }).chapters(),
    ).toEqual(chapters);
  });

  it('serves none while the body loads', () => {
    expect(sourceWith({ status: 'loading' }).chapters()).toEqual([]);
  });

  describe('transcript', () => {
    const transcript: TranscriptDto = {
      language: 'en',
      paragraphs: [{ startSeconds: 5, speaker: 'Ada', text: 'Hello' }],
    };

    const okBody = (html: string | null): EntryBodyState => ({
      status: 'ok',
      html,
      chapters: [],
      transcript,
    });

    it('serves the transcript of a loaded body', () => {
      expect(sourceWith(okBody('<p>x</p>')).transcript()).toEqual(transcript);
    });

    it('serves none while the body loads', () => {
      expect(sourceWith({ status: 'loading' }).transcript()).toBeNull();
    });

    it('is the body when the show notes hold no text', () => {
      expect(sourceWith(okBody('<p> </p>')).transcriptIsTheBody()).toBe(true);
    });

    it('is the body when there are no show notes at all', () => {
      expect(sourceWith(okBody(null)).transcriptIsTheBody()).toBe(true);
    });

    it('is not the body when the show notes hold text', () => {
      expect(sourceWith(okBody('<p>Show notes</p>')).transcriptIsTheBody()).toBe(false);
    });

    it('is not the body while the body loads', () => {
      expect(sourceWith({ status: 'loading' }).transcriptIsTheBody()).toBe(false);
    });
  });
});
