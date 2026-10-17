import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { aLinkCard, anEntry } from '../../../../testing/entry-testing';
import { LinkReaderNavigation } from '../../shell/link-reader-navigation.service';
import { LinkCardsComponent } from './link-cards.component';

describe('LinkCardsComponent', () => {
  it('opens the card whose button was pressed in the reader, by its position', () => {
    const navigation = { layer: jest.fn() };
    TestBed.configureTestingModule({
      imports: [LinkCardsComponent, provideTranslocoTesting()],
      providers: [{ provide: LinkReaderNavigation, useValue: navigation }],
    });
    const entry = anEntry({
      linkCards: [
        aLinkCard({ position: 2 }),
        aLinkCard({ url: 'https://other.test/', title: 'Second', position: 5 }),
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

    expect(navigation.layer).toHaveBeenCalledWith({ entryId: entry.id, position: 5 });
  });
});
