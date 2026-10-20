import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { aLinkCard, anEntry } from '../../../../testing/entry-testing';
import { LinkReaderNavigation } from '../../shell/link-reader-navigation.service';
import { LinkCardsComponent } from './link-cards.component';

describe('LinkCardsComponent', () => {
  it('opens the card whose button was pressed in the reader', () => {
    const navigation = { layer: jest.fn() };
    TestBed.configureTestingModule({
      imports: [LinkCardsComponent, provideTranslocoTesting()],
      providers: [{ provide: LinkReaderNavigation, useValue: navigation }],
    });
    const entry = anEntry({
      linkCards: [
        aLinkCard({ url: 'https://first.test/' }),
        aLinkCard({ url: 'https://other.test/', title: 'Second' }),
      ],
    });
    const fixture = TestBed.createComponent(LinkCardsComponent);
    fixture.componentRef.setInput('entry', entry);
    fixture.detectChanges();

    const buttons = (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>(
      'button.open-in-reader',
    );
    expect(buttons).toHaveLength(2);
    buttons[1].click();

    expect(navigation.layer).toHaveBeenCalledWith(entry.linkCards[1]);
  });
});
