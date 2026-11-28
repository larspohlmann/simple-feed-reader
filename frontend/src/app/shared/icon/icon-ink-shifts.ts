import measured from './icon-ink-shifts.json';

export const ICON_INK_SHIFTS_FONT_VERSION = measured.fontVersion;

export const ICON_INK_SHIFTS = measured.shifts as unknown as Readonly<
  Record<string, readonly [outlined: number, filled: number]>
>;
