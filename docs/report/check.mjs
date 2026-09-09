import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const html = readFileSync(new URL('public/index.html', import.meta.url), 'utf8');
const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]);
assert.equal(ids.length, new Set(ids).size, 'Document IDs must be unique');
assert(!/<(?:iframe|dialog)\b|拡大して読む|\{\{/.test(html), 'Plain document content only');
for (const branch of ['main', 'dev']) {
  assert(html.includes(`https://github.com/satokupo/welcart-tiered-discounts/tree/${branch}`));
}
assert(!html.includes('撮影後に掲載します'), 'Recorded videos replace the placeholders');
assert.equal([...html.matchAll(/<video\b/g)].length, 2, 'Two operation videos are embedded');
for (const name of ['buy3', 'edit2']) {
  const path = `media/${name}.mp4`;
  assert(html.includes(`src="${path}"`));
  const video = readFileSync(new URL(`public/${path}`, import.meta.url));
  assert(video.toString('ascii', 4, 8) === 'ftyp' && video.includes(Buffer.from('moov')) && video.includes(Buffer.from('mdat')), 'Each video is a real MP4 asset');
}
assert(!/<a\b[^>]*\bdownload\b/.test(html), 'Standalone video download links are omitted');
assert(html.includes('data-label="所要時間"'), 'Mobile time table retains column labels');
assert(!ids.some(id => id.startsWith('main-') || id.startsWith('dev-')), 'README is read on GitHub');
assert(ids.includes('overview-提出物について'));
assert(html.includes('<h1 id="page-top" tabindex="-1"><a href="./"'), 'Report title links to home');
for (const number of [3, 4]) {
  assert(html.includes(`href="https://github.com/satokupo/welcart-tiered-discounts/issues/${number}" target="_blank" rel="noopener noreferrer"`), 'Design sources open in a separate tab');
}

for (const [tag] of html.matchAll(/<a\b[^>]*>/g)) {
  if (/href=["'](?:https?:)?\/\//i.test(tag)) {
    assert(tag.includes('target="_blank"') && tag.includes('rel="noopener noreferrer"'), 'Every external link opens safely in a separate tab');
  } else if (/href="#/.test(tag)) {
    assert(!tag.includes('target="_blank"'), 'Internal navigation stays in the report');
    assert(ids.includes(tag.match(/href="#([^"]+)"/)[1]), 'Internal navigation must target an existing section');
  } else if (/href="\.\/"/.test(tag)) {
    assert(!tag.includes('target="_blank"'), 'Home link stays in the report tab');
  } else {
    assert(/href="(?:ui-comparison.html|implementation-review\/index.html)"/.test(tag) && tag.includes('target="_blank"') && tag.includes('rel="noopener noreferrer"'), 'Supporting documents open in separate tabs');
  }
}

assert(html.includes('href="implementation-review/index.html"'), 'Original implementation report is linked');
const review = readFileSync(new URL('public/implementation-review/index.html', import.meta.url), 'utf8');
assert(!/(?:href|src)="(?:\.\.\/|file:)|\/Users\/|\/Volumes\//.test(review), 'Archived report has no broken workspace references');
assert.equal([...review.matchAll(/<img\b/g)].length, 3, 'Original screenshots are retained');
const reviewAssets = new Set([...review.matchAll(/href="((?:evidence|images)\/[^"#]+)"/g)].map(match => match[1]));
assert.equal(reviewAssets.size, 22, 'Original evidence and linked screenshot are retained');
for (const path of reviewAssets) {
  assert(readFileSync(new URL(`public/implementation-review/${path}`, import.meta.url)).length > 0);
}

const ui = readFileSync(new URL('public/ui-comparison.html', import.meta.url), 'utf8');
assert(!/<(?:link\b[^>]*rel="stylesheet"|script\b[^>]*src=)|@import|\/Users\/|\/Volumes\//.test(ui), 'UI archive has no external files or local paths');
assert(!ui.includes('fonts.googleapis.com'), 'Font import is removed completely, including semicolons inside its URL');
assert.equal([...ui.matchAll(/<img\b/g)].length, 8);
assert.equal([...ui.matchAll(/src="data:image\/(?:png|jpeg);base64,/g)].length, 8);
for (let i = 1; i <= 8; i++) {
  assert(ui.includes(`href="#ui-image-${i}"`) && ui.includes(`id="ui-image-${i}"`));
}
const imageScript = ui.match(/<script id="image-view">([\s\S]*?)<\/script>/)[1];
for (const hash of ['', '#ui-image-1', '#ui-image-8', '#ui-image-9']) {
  let displayed = false;
  const fullImage = { alt: 'Comparison', style: {} };
  runInNewContext(imageScript, {
    location: { hash },
    document: {
      getElementById: () => ({ cloneNode: () => fullImage }),
      body: { style: {}, replaceChildren: image => { assert.equal(image, fullImage); displayed = true; } },
    },
  });
  assert.equal(displayed, ['#ui-image-1', '#ui-image-8'].includes(hash));
}

// Run the shipped controller against its small DOM contract, without a browser dependency.
const elements = new Map();
const tablist = { setAttribute() {} };
let focused;
for (const id of ids) {
  elements.set(id, {
    id, hidden: false, dataset: {}, attrs: {}, events: {}, parentElement: tablist,
    setAttribute(key, value) { this.attrs[key] = value; },
    addEventListener(key, value) { this.events[key] = value; },
    focus() { focused = this; },
    click() { this.events.click({ preventDefault() {} }); },
    get hash() { return this.href; },
  });
}
const reports = ['plugin', 'design', 'ai', 'recording'].map(name => {
  const tab = elements.get(`tab-${name}`);
  tab.dataset.tab = name;
  tab.href = name === 'plugin' ? '#plugin-main' : `#${name}`;
  return tab;
});
const versions = ['main', 'dev'].map(name => {
  const tab = elements.get(`tab-${name}`);
  tab.dataset.version = name;
  tab.href = `#plugin-${name}`;
  return tab;
});
const location = { hash: '' };
const listeners = {};
const videos = [0, 1].map(() => ({
  paused: true, ended: false, dataset: {}, events: {},
  addEventListener(name, listener) { this.events[name] = listener; },
}));
let reducedMotion = false;
let scrollRequest;
const window = {
  scrollY: 0,
  addEventListener: (name, listener) => { listeners[name] = listener; },
  matchMedia: () => ({ matches: reducedMotion }),
  scrollTo: options => { scrollRequest = options; },
};
runInNewContext(readFileSync(new URL('public/app.js', import.meta.url), 'utf8'), {
  document: {
    querySelectorAll: selector => ({ '[data-tab]': reports, '[data-version]': versions, '.operation-video': videos })[selector],
    getElementById: id => elements.get(id),
  },
  location,
  history: { pushState: (_state, _title, hash) => { location.hash = hash; } },
  window,
});
const visible = () => ['plugin-main', 'plugin-dev', 'design', 'ai', 'recording']
  .filter(id => !elements.get(id).hidden && (!id.startsWith('plugin-') || !elements.get('plugin').hidden));
assert.deepEqual(visible(), ['plugin-main']);
versions[1].click();
assert.deepEqual(visible(), ['plugin-dev']);
assert.equal(versions[1].attrs['aria-selected'], 'true');
for (const tab of reports.slice(1)) {
  tab.click();
  assert.deepEqual(visible(), [tab.dataset.tab]);
}
reports[0].click();
assert.deepEqual(visible(), ['plugin-dev'], 'Version is retained when returning to the plugin');
reports[0].events.keydown({ key: 'ArrowRight', preventDefault() {} });
assert.equal(focused, reports[1]);
assert.deepEqual(visible(), ['design']);
reports[1].events.keydown({ key: 'End', preventDefault() {} });
assert.deepEqual(visible(), ['recording']);
location.hash = '#plugin-main';
listeners.popstate();
assert.deepEqual(visible(), ['plugin-main']);
location.hash = '#unknown';
listeners.hashchange();
assert.deepEqual(visible(), ['plugin-main']);
versions[1].events.keydown({ key: ' ', preventDefault() {} });
assert.deepEqual(visible(), ['plugin-dev']);
const backToTop = elements.get('back-to-top');
assert.equal(backToTop.dataset.visible, 'false', 'Back-to-top is hidden at the top');
window.scrollY = 600;
listeners.scroll();
assert.equal(backToTop.dataset.visible, 'true', 'Back-to-top appears after scrolling');
backToTop.click();
assert.equal(scrollRequest.top, 0);
assert.equal(scrollRequest.behavior, 'smooth');
assert.equal(focused, elements.get('page-top'));
assert.deepEqual(visible(), ['plugin-dev'], 'Returning to the top keeps the selected report');
reducedMotion = true;
backToTop.click();
assert.equal(scrollRequest.behavior, 'instant');
window.scrollY = 0;
listeners.scroll();
assert.equal(backToTop.dataset.visible, 'false', 'Back-to-top hides again at the top');
for (const video of videos) {
  assert.equal(video.title, undefined, 'Video cursor has no tooltip');
  video.paused = false;
  video.events.play();
  assert.equal(video.dataset.playing, 'true');
  assert.equal(video.title, undefined, 'Playing video has no tooltip');
  video.paused = true;
  video.events.pause();
  assert.equal(video.dataset.playing, 'false');
  assert.equal(video.title, undefined, 'Paused video has no tooltip');
  video.ended = true;
  video.events.ended();
  assert.equal(video.dataset.playing, 'false');
}
console.log('PASS: document structure, branch links, mobile labels, tab/version switching, keyboard, history, back-to-top, video cursors');
