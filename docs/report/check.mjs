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
for (const number of [3, 4]) {
  assert(html.includes(`href="https://github.com/satokupo/welcart-tiered-discounts/issues/${number}" target="_blank" rel="noopener noreferrer"`), 'Design sources open in a separate tab');
}

for (const [tag] of html.matchAll(/<a\b[^>]*>/g)) {
  if (/href=["'](?:https?:)?\/\//i.test(tag)) {
    assert(tag.includes('target="_blank"') && tag.includes('rel="noopener noreferrer"'), 'Every external link opens safely in a separate tab');
  } else if (/href="#/.test(tag)) {
    assert(!tag.includes('target="_blank"'), 'Internal navigation stays in the report');
  } else {
    assert(tag.includes('href="ui-comparison.html"') && tag.includes('target="_blank"') && tag.includes('rel="noopener noreferrer"'), 'UI document opens in a separate tab');
  }
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
runInNewContext(readFileSync(new URL('public/app.js', import.meta.url), 'utf8'), {
  document: {
    querySelectorAll: selector => selector === '[data-tab]' ? reports : versions,
    getElementById: id => elements.get(id),
  },
  location,
  history: { pushState: (_state, _title, hash) => { location.hash = hash; } },
  window: { addEventListener: (name, listener) => { listeners[name] = listener; } },
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
console.log('PASS: document structure, branch links, mobile labels, tab/version switching, keyboard, history');
