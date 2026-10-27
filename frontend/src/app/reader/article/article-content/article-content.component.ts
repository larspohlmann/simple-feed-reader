import { Component, ElementRef, inject, input, output, signal } from '@angular/core';
import { ArticleBody } from '../reader-view/article-body.service';
import { AudioTrack } from '../../audio-player.service';
import { ChapterDto, EntryDto, TranscriptDto } from '../../models';

/** The article body: its typography and every decoration `ArticleBody` gives it. */
@Component({
  selector: 'app-article-content',
  template: '',
  styleUrls: [
    './article-content.component.scss',
    './article-chapters.scss',
    './article-transcript.scss',
  ],
  providers: [ArticleBody],
  host: { class: 'content', '[innerHTML]': 'html()' },
})
export class ArticleContentComponent {
  readonly html = input.required<string>();
  readonly pageUrl = input.required<string | null>();
  readonly enclosureUrl = input<string | null>(null);
  readonly chapters = input<ChapterDto[]>([]);
  readonly transcript = input<TranscriptDto | null>(null);
  readonly transcriptIsTheBody = input(false);
  readonly audioTrack = input<AudioTrack | null>(null);
  readonly entry = input<EntryDto | null>(null);
  readonly decorated = output<HTMLElement>();

  readonly element = inject<ElementRef<HTMLElement>>(ElementRef);

  constructor() {
    inject(ArticleBody).connect({
      content: signal(this.element),
      html: this.html,
      pageUrl: this.pageUrl,
      enclosureUrl: this.enclosureUrl,
      chapters: this.chapters,
      transcript: this.transcript,
      transcriptIsTheBody: this.transcriptIsTheBody,
      audioTrack: this.audioTrack,
      entry: this.entry,
      decorated: (host) => this.decorated.emit(host),
    });
  }
}
