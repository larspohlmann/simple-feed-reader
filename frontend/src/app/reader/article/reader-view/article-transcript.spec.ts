import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AudioPlayerService } from '../../audio-player.service';
import { TranscriptDto } from '../../models';
import { ArticleTranscript } from './article-transcript';

const TRANSCRIPT: TranscriptDto = {
  language: null,
  paragraphs: [
    { startSeconds: 0, speaker: null, text: 'One.' },
    { startSeconds: 60, speaker: null, text: 'Two.' },
  ],
};
const VIEW = {
  transcript: TRANSCRIPT,
  heading: 'Transcript',
  playFromLabel: (time: string) => `Play from ${time}`,
  open: false,
};
const TRACK = {
  url: 'https://x.test/ep.mp3',
  title: 'Ep',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 600,
};

function transcript(): ArticleTranscript {
  return new ArticleTranscript(TestBed.inject(AudioPlayerService), TestBed.inject(Injector));
}

describe('ArticleTranscript', () => {
  beforeEach(() => localStorage.clear());

  it('leaves one section after attaching twice', () => {
    const host = document.createElement('div');
    const section = transcript();

    section.attach(host, VIEW, null);
    section.attach(host, VIEW, null);

    expect(host.querySelectorAll('.reader-transcript')).toHaveLength(1);
  });

  it('removes its section on release', () => {
    const host = document.createElement('div');
    const section = transcript();
    section.attach(host, VIEW, TRACK);

    section.release();

    expect(host.querySelector('.reader-transcript')).toBeNull();
  });

  it('starts the episode at the clicked timestamp', () => {
    const playFrom = jest
      .spyOn(TestBed.inject(AudioPlayerService), 'playFrom')
      .mockImplementation(() => undefined);
    const host = document.createElement('div');
    transcript().attach(host, VIEW, TRACK);

    host.querySelectorAll<HTMLButtonElement>('.reader-transcript__time')[1].click();

    expect(playFrom).toHaveBeenCalledWith(TRACK, 60);
  });
});
