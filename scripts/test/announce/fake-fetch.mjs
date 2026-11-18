export function fakeFetch(responses) {
  const calls = [];
  const fetch = async (url, init) => {
    calls.push({ url, init, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body });
    const [status, payload] = responses.shift();
    return new Response(JSON.stringify(payload), { status });
  };
  return { fetch, calls };
}
