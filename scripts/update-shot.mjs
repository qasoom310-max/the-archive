#!/usr/bin/env node
// Take one picture for a "What's new" entry, from the local DEMO copy.
//
//   1. scripts/demo-server.sh                       (in another terminal)
//   2. node scripts/update-shot.mjs <entry-key> <picture-name> <path> [options]
//
//   node scripts/update-shot.mjs 2026-11-16-whats-new-page list-after /updates
//
// Saves resources/updates/images/<entry-key>/<picture-name>.png. Then name the
// file in the entry's "images" list. Take the "before" picture BEFORE you change
// the screen.
//
// Options:
//   --selector=CSS   picture of one element only (the part that changed)
//   --click=CSS      click this first (open a menu or a dialog); repeatable
//   --wait=CSS       wait for this to appear before the picture
//   --full           the whole page, not just the first screen
//   --width=1280 --height=800
//   --mobile         a phone-sized screen (390 x 844)
//   --ar             the Arabic, right-to-left screen (signs in as the Arabic demo admin)
//   --dark           dark mode
//
// It refuses any address but the local demo copy: a picture of the live system
// would publish customers' names, phones and money to everyone who can sign in.
import { existsSync, mkdirSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const BASE = 'http://127.0.0.1:8765';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const args = process.argv.slice(2);
const positional = args.filter((a) => !a.startsWith('--'));
const flag = (name) => args.includes(`--${name}`);
const option = (name) => args.find((a) => a.startsWith(`--${name}=`))?.split('=').slice(1).join('=');
const options = (name) => args.filter((a) => a.startsWith(`--${name}=`)).map((a) => a.split('=').slice(1).join('='));

const [key, name, path] = positional;
const fail = (message) => { console.error(message); process.exit(1); };

if (!key || !name || !path) fail('Usage: node scripts/update-shot.mjs <entry-key> <picture-name> <path> [options]');
if (!/^\d{4}-\d{2}-\d{2}-[a-z0-9]+(-[a-z0-9]+)*$/.test(key)) fail('The entry key must look like 2026-11-16-short-name.');
if (!/^[a-z0-9][a-z0-9-]*$/.test(name)) fail('The picture name takes lowercase letters, digits and dashes only (no extension).');
if (!path.startsWith('/')) fail('Give a path on the demo copy, such as /updates or /app/rental/order. Full addresses are refused.');

const chrome = [
    process.env.CHROME_PATH,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
].find((p) => p && existsSync(p));
if (!chrome) fail('Google Chrome was not found. Set CHROME_PATH to its program file.');

const mobile = flag('mobile');
const width = Number(option('width') ?? (mobile ? 390 : 1280));
const height = Number(option('height') ?? (mobile ? 844 : 800));

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ['--hide-scrollbars'] });
try {
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: 2, isMobile: mobile, hasTouch: mobile });
    if (flag('dark')) await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);

    // Sign in to the demo copy with the seeded demo admin.
    try {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0', timeout: 15000 });
    } catch {
        fail(`The demo copy is not running on ${BASE}. Start it with: scripts/demo-server.sh`);
    }
    const account = flag('ar') ? 'arabic@example.com' : 'admin@example.com';
    await page.type('input[autocomplete="username"]', account);
    await page.type('input[autocomplete="current-password"], input[wire\\:model="password"]', 'password');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);
    if (new URL(page.url()).pathname === '/login') fail(`Could not sign in to the demo copy as ${account}.`);

    const response = await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle0', timeout: 20000 });
    if (!response || response.status() >= 400) fail(`${path} answered ${response?.status() ?? 'nothing'} on the demo copy.`);

    for (const selector of options('click')) {
        await page.waitForSelector(selector, { visible: true, timeout: 8000 });
        await page.click(selector);
        await new Promise((r) => setTimeout(r, 500));
    }
    const wait = option('wait');
    if (wait) await page.waitForSelector(wait, { visible: true, timeout: 8000 });
    await new Promise((r) => setTimeout(r, 300));

    const dir = join(root, 'resources', 'updates', 'images', key);
    mkdirSync(dir, { recursive: true });
    const file = join(dir, `${name}.png`);

    const selector = option('selector');
    if (selector) {
        const element = await page.waitForSelector(selector, { visible: true, timeout: 8000 });
        await element.screenshot({ path: file });
    } else {
        await page.screenshot({ path: file, fullPage: flag('full') });
    }

    const kb = Math.round(statSync(file).size / 1024);
    console.log(`Saved resources/updates/images/${key}/${name}.png (${kb} KB)`);
    if (kb > 400) console.log('That is over 400 KB. Use --selector to crop to the part that changed.');
} finally {
    await browser.close();
}
