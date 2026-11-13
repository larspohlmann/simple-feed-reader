export function fakeFetch(responses) {
  const calls = [];
  const fetch = async (url, init) => {
    calls.push({ url, init, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body });
    const [status, payload, headers = {}] = responses.shift();
    return new Response(payload === null ? null : JSON.stringify(payload), { status, headers });
  };
  return { fetch, calls };
}
