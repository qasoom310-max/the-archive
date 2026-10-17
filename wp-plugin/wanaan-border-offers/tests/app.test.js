// The search box in a real browser: runs tests/app.test.html in headless
// Chrome (or Edge) against a fake ERP and reads back each check.
// Run: node --test tests/app.test.js   (from the plugin folder or the repo root)
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');

const plugin = path.resolve(__dirname, '..');

/** The strings, straight from the PHP class, so the two can never drift. */
function strings() {
	const php = "define('ABSPATH', 1); require 'includes/class-wbo-query.php'; require 'includes/class-wbo-strings.php';" +
		"echo json_encode(['en' => WBO_Strings::english(), 'ar' => WBO_Strings::arabic()], JSON_UNESCAPED_UNICODE);";
	return JSON.parse(execFileSync('php', ['-r', php], { cwd: plugin, encoding: 'utf8' }));
}

function browser() {
	const candidates = [
		process.env.CHROME_BIN,
		'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
		'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
		'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
		'/usr/bin/google-chrome',
		'/usr/bin/chromium',
		'/usr/bin/chromium-browser',
		'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
	];
	return candidates.find((p) => p && fs.existsSync(p)) || null;
}

const all = strings();

test('English and Arabic have the same words', () => {
	assert.deepEqual(Object.keys(all.ar).sort(), Object.keys(all.en).sort());
	for (const [key, value] of Object.entries(all.ar)) {
		assert.ok(value.trim() !== '', 'empty Arabic for ' + key);
		const en = all.en[key].match(/:\w+/g) || [];
		const ar = value.match(/:\w+/g) || [];
		assert.deepEqual(ar.sort(), en.sort(), 'placeholders differ for ' + key);
	}
});

test('every word the box uses exists', () => {
	const js = fs.readFileSync(path.join(plugin, 'assets/wbo-app.js'), 'utf8') + fs.readFileSync(path.join(plugin, 'assets/wbo-core.js'), 'utf8');
	const used = new Set();
	for (const m of js.matchAll(/\bt\(\s*'([a-z_]+)'/g)) used.add(m[1]);
	for (const m of js.matchAll(/\bt\(s, '([a-z_]+)'/g)) used.add(m[1]);
	for (const m of js.matchAll(/this\.t\(\s*([a-z_]+)\s*\?\s*'([a-z_]+)'\s*:\s*'([a-z_]+)'/g)) { used.add(m[2]); used.add(m[3]); }
	assert.ok(used.size > 20, 'found the words: ' + used.size);
	for (const key of used) {
		assert.ok(key in all.en, 'missing word: ' + key);
	}
});

test('the box works in a browser', { skip: browser() === null && 'no Chrome or Edge found' }, () => {
	fs.writeFileSync(
		path.join(__dirname, 'strings.generated.js'),
		'window.WBO_STRINGS_EN = ' + JSON.stringify(all.en) + ';\nwindow.WBO_STRINGS_AR = ' + JSON.stringify(all.ar) + ';\n',
	);

	const dom = execFileSync(browser(), [
		'--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files',
		'--virtual-time-budget=20000', '--dump-dom',
		pathToFileURL(path.join(__dirname, 'app.test.html')).href,
	], { encoding: 'utf8', timeout: 90000 });

	const m = dom.match(/<pre id="wbo-result">([\s\S]*?)<\/pre>/);
	assert.ok(m && m[1].trim() !== '', 'the page reported nothing');
	const results = JSON.parse(m[1].replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&'));

	assert.ok(results.length >= 14, 'ran ' + results.length + ' checks');
	const failed = results.filter((r) => !r.ok);
	for (const r of results) {
		console.log((r.ok ? '  ✔ ' : '  ✖ ') + r.name + (r.ok ? '' : ' — ' + r.error));
	}
	assert.deepEqual(failed, []);
});
