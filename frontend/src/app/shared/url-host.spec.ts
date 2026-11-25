import { hostOf, parseHttpUrl, parseUrl, stripWww } from './url-host';

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

it('strips only a leading www. from a bare host', () => {
  expect(stripWww('www.example.com')).toBe('example.com');
  expect(stripWww('wwwexample.com')).toBe('wwwexample.com');
  expect(stripWww('news.www.example.com')).toBe('news.www.example.com');
});

it('reads only http and https addresses as web URLs', () => {
  expect(parseHttpUrl('https://example.com/a')?.origin).toBe('https://example.com');
  expect(parseHttpUrl('javascript:alert(1)')).toBeNull();
  expect(parseHttpUrl('mailto:a@example.com')).toBeNull();
  expect(parseHttpUrl('not a url')).toBeNull();
});
