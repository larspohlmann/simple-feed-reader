import { formatEta } from './eta-format';

describe('formatEta', () => {
  it('renders sub-minute values in seconds', () => {
    expect(formatEta(30)).toEqual({ key: 'reader.eta.seconds', params: { count: 30 } });
    expect(formatEta(59)).toEqual({ key: 'reader.eta.seconds', params: { count: 59 } });
  });

  it('renders a full minute and above in whole minutes, rounding up', () => {
    expect(formatEta(60)).toEqual({ key: 'reader.eta.minutes', params: { count: 1 } });
    expect(formatEta(61)).toEqual({ key: 'reader.eta.minutes', params: { count: 2 } });
    expect(formatEta(120)).toEqual({ key: 'reader.eta.minutes', params: { count: 2 } });
    expect(formatEta(121)).toEqual({ key: 'reader.eta.minutes', params: { count: 3 } });
  });

  it('never shows zero or a fraction', () => {
    expect(formatEta(0)).toEqual({ key: 'reader.eta.seconds', params: { count: 1 } });
    expect(formatEta(0.4)).toEqual({ key: 'reader.eta.seconds', params: { count: 1 } });
  });
});
