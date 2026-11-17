import { TestBed } from '@angular/core/testing';
import { LemmyCommunityMemory } from './lemmy-community-memory';

describe('LemmyCommunityMemory', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => jest.restoreAllMocks());

  it('remembers a community per account, read back by a new instance', () => {
    const memory = TestBed.inject(LemmyCommunityMemory);
    memory.remember(3, 41);
    memory.remember(9, 77);
    TestBed.resetTestingModule();

    const recalled = TestBed.inject(LemmyCommunityMemory);
    expect(recalled.recall(3)).toBe(41);
    expect(recalled.recall(9)).toBe(77);
  });

  it('keeps one key for every account', () => {
    TestBed.inject(LemmyCommunityMemory).remember(3, 41);

    expect(JSON.parse(localStorage.getItem('sfr.composer.lemmyCommunity')!)).toEqual({ 3: 41 });
  });

  it('recalls nothing for an unknown account', () => {
    const memory = TestBed.inject(LemmyCommunityMemory);
    memory.remember(3, 41);

    expect(memory.recall(4)).toBeNull();
  });

  it.each(['not json', '[1,2]', '{"3":"forty"}', 'null'])(
    'recalls nothing from a stored %s',
    (stored) => {
      localStorage.setItem('sfr.composer.lemmyCommunity', stored);

      expect(TestBed.inject(LemmyCommunityMemory).recall(3)).toBeNull();
    },
  );

  it('replaces a garbage store on remember', () => {
    localStorage.setItem('sfr.composer.lemmyCommunity', 'not json');
    const memory = TestBed.inject(LemmyCommunityMemory);

    memory.remember(3, 41);

    expect(memory.recall(3)).toBe(41);
  });

  it('neither recalls nor remembers, without throwing, while storage is blocked', () => {
    const blocked = () => {
      throw new DOMException('blocked', 'SecurityError');
    };
    jest.spyOn(Storage.prototype, 'getItem').mockImplementation(blocked);
    jest.spyOn(Storage.prototype, 'setItem').mockImplementation(blocked);
    const memory = TestBed.inject(LemmyCommunityMemory);

    expect(() => memory.remember(3, 41)).not.toThrow();
    expect(memory.recall(3)).toBeNull();
  });
});
