/* Hash links keep the selected report shareable and browser Back useful. */
const reportTabs = [...document.querySelectorAll('[data-tab]')];
const versionTabs = [...document.querySelectorAll('[data-version]')];
const reportNames = ['plugin', 'design', 'ai', 'recording'];
let version = 'main';

function selectTabs(tabs, selected) {
  for (const tab of tabs) {
    const active = tab === selected;
    tab.setAttribute('aria-selected', String(active));
    tab.tabIndex = active ? 0 : -1;
  }
}

function showReport() {
  const hash = location.hash.slice(1);
  const report = reportNames.includes(hash) ? hash : 'plugin';
  if (hash === 'plugin-dev' || hash === 'plugin-main') version = hash.slice(7);
  selectTabs(reportTabs, reportTabs.find(tab => tab.dataset.tab === report));
  selectTabs(versionTabs, versionTabs.find(tab => tab.dataset.version === version));
  for (const name of reportNames) document.getElementById(name).hidden = name !== report;
  for (const name of ['main', 'dev']) document.getElementById(`plugin-${name}`).hidden = name !== version;
  reportTabs[0].href = `#plugin-${version}`;
}

for (const tabs of [reportTabs, versionTabs]) {
  tabs[0].parentElement.setAttribute('role', 'tablist');
  for (const tab of tabs) {
    tab.setAttribute('role', 'tab');
    const panel = tab.dataset.tab || `plugin-${tab.dataset.version}`;
    tab.setAttribute('aria-controls', panel);
    document.getElementById(panel).setAttribute('role', 'tabpanel');
    tab.addEventListener('click', event => {
      event.preventDefault();
      if (location.hash !== tab.hash) history.pushState(null, '', tab.hash);
      showReport();
    });
    tab.addEventListener('keydown', event => {
      if (event.key === ' ') {
        event.preventDefault();
        tab.click();
        return;
      }
      let index = tabs.indexOf(tab);
      if (event.key === 'ArrowRight') index = (index + 1) % tabs.length;
      else if (event.key === 'ArrowLeft') index = (index - 1 + tabs.length) % tabs.length;
      else if (event.key === 'Home') index = 0;
      else if (event.key === 'End') index = tabs.length - 1;
      else return;
      event.preventDefault();
      tabs[index].focus();
      tabs[index].click();
    });
  }
}
window.addEventListener('hashchange', showReport);
window.addEventListener('popstate', showReport);
showReport();
<<<<<<< HEAD

const backToTop = document.getElementById('back-to-top');
const updateBackToTop = () => { backToTop.dataset.visible = String(window.scrollY >= 300); };
window.addEventListener('scroll', updateBackToTop, { passive: true });
backToTop.addEventListener('click', () => {
  document.getElementById('page-top').focus({ preventScroll: true });
  window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
});
updateBackToTop();

for (const video of document.querySelectorAll('.operation-video')) {
  const updateVideoCursor = () => {
    const playing = !video.paused && !video.ended;
    video.dataset.playing = String(playing);
  };
  for (const event of ['play', 'pause', 'ended', 'emptied']) video.addEventListener(event, updateVideoCursor);
  updateVideoCursor();
}
=======
>>>>>>> codex/submission-report
