(() => {
  const root = document.documentElement;
  const systemDark = matchMedia('(prefers-color-scheme: dark)');
  const isDark = () => root.dataset.theme === 'dark' || (!root.dataset.theme && systemDark.matches);
  const button = document.querySelector('.theme-switch');
  const label = () => { button.setAttribute('aria-label', isDark() ? 'Switch to light theme' : 'Switch to dark theme'); };
  button.addEventListener('click', () => {
    const next = isDark() ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem('sfr.homepage.theme', next); } catch (error) {}
    label();
  });
  systemDark.addEventListener('change', label);
  label();
})();
