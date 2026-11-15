import { hostOf, parseUrl } from './url-host';

it('parses a URL around stray whitespace', () => {
  expect(parseUrl(' https://example.com/a ')?.pathname).toBe('/a');
});

it('reads no URL from text that is none', () => {
  expect(parseUrl('not a url')).toBeNull();
});

it('names a host without its www.', () => {
  expect(hostOf(new URL('https://www.example.com/a'))).toBe('example.com');
  expect(hostOf(new URL('https://news.example.com/a'))).toBe('news.example.com');
});
