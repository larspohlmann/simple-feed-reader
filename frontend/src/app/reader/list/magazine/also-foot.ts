import { EntryDto } from '../../models';

/** What a card's footer lists, and the translation key it is headed with. */
export interface AlsoFoot {
  readonly copies: readonly EntryDto[];
  readonly label: string;
}

const NO_COPIES: readonly EntryDto[] = [];

export function alsoFootOf(entry: EntryDto): AlsoFoot {
  return { copies: entry.duplicates ?? NO_COPIES, label: 'reader.alsoIn' };
}
