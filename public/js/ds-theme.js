/* Dot.Design: applied synchronously in <head> so the first paint already has the visitor's theme and list/grid choice. No inline script, so it is CSP-clean. */
(function () {
    var root = document.documentElement;
    try {
        var theme = localStorage.getItem('dot-design-theme');
        if (theme === 'light' || theme === 'dark') root.setAttribute('data-theme', theme);
        var view = localStorage.getItem('dot-design-view');
        if (view === 'list' || view === 'grid') root.setAttribute('data-view', view);
    } catch (e) { /* storage blocked: system theme and grid view */ }
    root.classList.add('js');
})();
