(function () {
  try {
    var choice = localStorage.getItem('sfr.homepage.theme');
    if (choice === 'light' || choice === 'dark') document.documentElement.setAttribute('data-theme', choice);
  } catch (error) {}
})();
