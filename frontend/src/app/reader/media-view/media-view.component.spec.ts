import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { MediaViewComponent } from './media-view.component';
import { AudioPlayerService } from '../audio-player.service';
import { EntryDto, EntryMediumDto } from '../models';

function playerStub() {
  return { play: jest.fn() };
}

let player: ReturnType<typeof playerStub>;

function render(mode: 'pictures' | 'videos' | 'audios', entries: EntryDto[]) {
  player = playerStub();
  TestBed.configureTestingModule({
    imports: [MediaViewComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: player }],
  });
  const fixture = TestBed.createComponent(MediaViewComponent);
  fixture.componentRef.setInput('mode', mode);
  fixture.componentRef.setInput('entries', entries);
  fixture.detectChanges();
  return fixture;
}

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Headline',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  media: [],
  attachments: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
  source: 'Src',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

const image = (url: string): EntryMediumDto => ({ url, kind: 'image' });
const video = (url: string, poster?: string): EntryMediumDto => ({
  url,
  kind: 'video',
  previewImageUrl: poster,
});

describe('MediaViewComponent', () => {
  describe('pictures', () => {
    it('renders a tile per image', () => {
      const fixture = render('pictures', [
        entry({ id: 1, media: [image('https://x.test/a.jpg')] }),
        entry({ id: 2, media: [image('https://x.test/b.jpg')] }),
      ]);

      expect(fixture.debugElement.queryAll(By.css('.tile img'))).toHaveLength(2);
    });

    it('emits open with the owning entry when a tile is clicked', () => {
      const owner = entry({ id: 42, media: [image('https://x.test/a.jpg')] });
      const fixture = render('pictures', [owner]);
      const opened: EntryDto[] = [];
      fixture.componentInstance.open.subscribe((e) => opened.push(e));

      fixture.debugElement.query(By.css('.tile')).nativeElement.click();

      expect(opened).toEqual([owner]);
    });

    it('shows an empty state when no entry declares an image', () => {
      const fixture = render('pictures', [entry({ id: 1 })]);

      expect(fixture.debugElement.query(By.css('.tile'))).toBeNull();
      expect(fixture.debugElement.query(By.css('.empty'))).not.toBeNull();
    });
  });

  describe('videos', () => {
    it('renders a poster and a play badge per video', () => {
      const fixture = render('videos', [
        entry({ id: 1, media: [video('https://x.test/v.mp4', 'https://x.test/p.jpg')] }),
      ]);

      expect(
        fixture.debugElement.query(By.css('.tile img')).nativeElement.getAttribute('src'),
      ).toBe('https://x.test/p.jpg');
      expect(fixture.debugElement.query(By.css('.tile .play-badge'))).not.toBeNull();
    });

    it('opens the entry when a video tile is clicked', () => {
      const owner = entry({ id: 7, media: [video('https://x.test/v.mp4')] });
      const fixture = render('videos', [owner]);
      const opened: EntryDto[] = [];
      fixture.componentInstance.open.subscribe((e) => opened.push(e));

      fixture.debugElement.query(By.css('.tile')).nativeElement.click();

      expect(opened).toEqual([owner]);
    });
  });

  describe('audios', () => {
    const withAudio = entry({
      id: 5,
      title: 'Episode 5',
      attachments: [{ url: 'https://x.test/ep.mp3', mimeType: 'audio/mpeg' }],
    });

    it('renders a row per audio entry', () => {
      const fixture = render('audios', [withAudio, entry({ id: 6 })]);

      expect(fixture.debugElement.queryAll(By.css('.audio-row'))).toHaveLength(1);
      expect(fixture.nativeElement.textContent).toContain('Episode 5');
    });

    it('plays the enclosure through the player when the play control is clicked', () => {
      const fixture = render('audios', [withAudio]);

      fixture.debugElement.query(By.css('.audio-row .play')).nativeElement.click();

      expect(player.play).toHaveBeenCalledWith(
        expect.objectContaining({ url: 'https://x.test/ep.mp3', title: 'Episode 5' }),
      );
    });

    it('opens the entry when the row title is clicked', () => {
      const fixture = render('audios', [withAudio]);
      const opened: EntryDto[] = [];
      fixture.componentInstance.open.subscribe((e) => opened.push(e));

      fixture.debugElement.query(By.css('.audio-row .audio-title')).nativeElement.click();

      expect(opened).toEqual([withAudio]);
    });
  });
});
