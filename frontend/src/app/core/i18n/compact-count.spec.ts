import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Lang } from './language';
import { LanguageService } from './language.service';
import { CompactCount } from './compact-count';

describe('CompactCount', () => {
  it('formatsALargeCountCompactly', () => {
    TestBed.configureTestingModule({
      providers: [{ provide: LanguageService, useValue: { lang: signal<Lang>('en') } }],
    });

    expect(TestBed.inject(CompactCount).format(1284)).toBe('1.3K');
  });

  it('followsTheLanguage', () => {
    const lang = signal<Lang>('en');
    TestBed.configureTestingModule({
      providers: [{ provide: LanguageService, useValue: { lang } }],
    });
    const compactCount = TestBed.inject(CompactCount);

    lang.set('de');

    expect(compactCount.format(1500)).toBe(
      new Intl.NumberFormat('de', { notation: 'compact' }).format(1500),
    );
  });
});
