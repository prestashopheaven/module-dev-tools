/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    George van Engers | PrestaShopHeaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

/*
 * Runs INSIDE the docs-tools container (resources/docs/Dockerfile), started by src/docs.php.
 *
 *   node runner.cjs /job/job.json
 *
 * The job file holds a list of tasks:
 *   {type: "screenshots", base, admin, login: {email, password}, module, proxy: {port, host, target_port}, hide: [css], shots: [...]}
 *   {type: "pdf", items: [{html, out}]}
 *   {type: "svg-png", items: [{in, out, size}]}
 *
 * The shop answers on http://localhost:<port> only (PS_DOMAIN). The container joins the
 * Docker network of the shop and forwards localhost:<port> to prestashop:80 with a small
 * TCP proxy, so the shop sees exactly the host name it was installed with.
 *
 * The result is written to /job/result.json: [{task, id, ok, error, out, url}].
 */

'use strict';

const fs = require('fs');
const net = require('net');
const path = require('path');
const { chromium } = require('playwright-core');

const VIEWPORTS = {
    // Addons product images: at least 1000 x 1000 px, so desktop is 1440 x 1000 and mobile is rendered at 3x.
    desktop: { viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 },
    tablet: { viewport: { width: 820, height: 1180 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
    mobile: {
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 3,
        isMobile: true,
        hasTouch: true,
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    },
};

// Developer-mode chrome of the test shops that must not end up in marketing screenshots.
const DEFAULT_HIDE = [
    '.sf-toolbar', '.sf-minitoolbar', '[id^="sfwdt"]', '[id^="sfToolbar"]', '.sf-toolbarreset',
    '#debug-mode', '.debug-mode', '#header_debug_mode', '.alert-debug-mode',
];

const ERROR_NEEDLES = ['Fatal error', 'Parse error', 'Uncaught ', 'Stack trace', 'Invalid token', 'Whoops, looks like something went wrong'];

/**
 * Starts a TCP proxy from 127.0.0.1:<port> (and ::1 when available) to <host>:<targetPort>.
 */
function startProxy(port, host, targetPort) {
    const handler = (client) => {
        const upstream = net.connect(targetPort, host);
        client.pipe(upstream).pipe(client);
        const close = () => { client.destroy(); upstream.destroy(); };
        client.on('error', close);
        upstream.on('error', close);
    };
    const servers = [];
    return Promise.all(['127.0.0.1', '::1'].map((address) => new Promise((resolve) => {
        const server = net.createServer(handler);
        server.on('error', () => resolve(null));
        server.listen(port, address, () => { servers.push(server); resolve(server); });
    }))).then(() => servers);
}

/**
 * Extracts the hidden input fields of a login form from its HTML.
 */
function hiddenInputs(html) {
    const fields = {};
    for (const input of html.match(/<input[^>]*type="hidden"[^>]*>/gi) || []) {
        const name = input.match(/name="([^"]+)"/);
        const value = input.match(/value="([^"]*)"/);
        if (name) {
            fields[decode(name[1])] = decode(value ? value[1] : '');
        }
    }
    return fields;
}

/**
 * Decodes the few HTML entities that occur in attribute values.
 */
function decode(s) {
    return s.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>');
}

/**
 * Logs in to the back office with the request context of the browser context (shares the cookies),
 * the same way as the back-office smoke test of ps-test.php. Returns {token, dashboard}.
 */
async function loginBackOffice(context, task) {
    const admin = task.base + task.admin;
    const loginPage = await context.request.get(admin + '?controller=AdminLogin', { timeout: 180000 });
    const fields = hiddenInputs(await loginPage.text());
    fields.email = task.login.email;
    fields.passwd = task.login.password;
    fields.submitLogin = '1';
    const response = await context.request.post(loginPage.url(), { form: fields, timeout: 180000 });
    const body = await response.text();
    const match = (body + ' ' + response.url()).match(/_token=([A-Za-z0-9_\-.]+)/);
    if (!match) {
        throw new Error('Back-office login failed (HTTP ' + response.status() + ', landed on ' + response.url() + ')');
    }
    return { token: match[1], dashboard: body };
}

/**
 * Turns the path of a back-office screenshot into a URL with a valid token.
 *   @configure        the configuration page of the module (Module Manager action)
 *   @menu:<n>         the n-th back-office menu link of the module
 *   /some/route       a Symfony route below index.php (the _token is appended)
 *   index.php?controller=AdminX...  a legacy controller (its token is taken from the menu)
 */
function backOfficeUrl(task, shot, session) {
    const admin = task.base + task.admin;
    const symfony = (p) => admin + p + (p.includes('?') ? '&' : '?') + '_token=' + session.token;
    const p = shot.path;
    if (p === '@configure') {
        return symfony('/improve/modules/manage/action/configure/' + task.module);
    }
    if (p.startsWith('@menu')) {
        const n = parseInt(p.split(':')[1] || '1', 10);
        const name = task.module.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const re = new RegExp('href="([^"]*(?:/modules/' + name + '/|controller=Admin' + name + ')[^"]*)"', 'gi');
        const links = [...new Set([...session.dashboard.matchAll(re)].map((m) => decode(m[1])))];
        if (!links[n - 1]) {
            throw new Error('The back office has no menu link ' + n + ' for the module (found ' + links.length + ').');
        }
        return links[n - 1].startsWith('http') ? links[n - 1] : task.base + links[n - 1];
    }
    const legacy = p.match(/controller=([A-Za-z0-9_]+)/);
    if (legacy && !p.includes('token=')) {
        const re = new RegExp('href="([^"]*controller=' + legacy[1] + '(?:&|&amp;)[^"]*token=[^"]*)"', 'i');
        const found = session.dashboard.match(re);
        if (found) {
            const link = decode(found[1]);
            const extra = p.split('?')[1] || '';
            const url = link.startsWith('http') ? link : (link.startsWith('/') ? task.base + link : admin.replace(/index\.php$/, '') + link);
            return url + (extra.replace(/(^|&)controller=[^&]*/, '') ? '&' + extra.replace(/(^|&)controller=[^&]*/, '').replace(/^&/, '') : '');
        }
        return admin + (p.startsWith('index.php') ? p.slice('index.php'.length) : '?' + p.replace(/^\?/, ''));
    }
    return symfony(p.startsWith('/') ? p : '/' + p);
}

/**
 * Takes all screenshots of one task (one language) in one browser.
 */
async function screenshots(browser, task, results) {
    const proxy = await startProxy(task.proxy.port, task.proxy.host, task.proxy.target_port);
    if (proxy.length === 0) {
        throw new Error('Could not listen on port ' + task.proxy.port + ' inside the container.');
    }
    const hide = DEFAULT_HIDE.concat(task.hide || []);
    const contexts = {};
    let session = null;
    try {
        for (const shot of task.shots) {
            const result = { task: 'screenshot', id: shot.id, language: shot.language, ok: false, error: '', out: shot.out, url: '' };
            results.push(result);
            try {
                const viewport = typeof shot.viewport === 'object' ? { viewport: shot.viewport, deviceScaleFactor: shot.viewport.scale || 1 } : VIEWPORTS[shot.viewport || 'desktop'];
                if (!viewport) {
                    throw new Error('Unknown viewport "' + shot.viewport + '" (use desktop, tablet, mobile or {width, height}).');
                }
                const key = JSON.stringify(viewport);
                if (!contexts[key]) {
                    contexts[key] = await browser.newContext({ ...viewport, locale: shot.locale || 'en-US', ignoreHTTPSErrors: true });
                }
                const context = contexts[key];
                let url = task.base + shot.path;
                if (shot.area === 'bo') {
                    if (!context._loggedIn) {
                        session = await loginBackOffice(context, task);
                        context._loggedIn = true;
                        context._session = session;
                    }
                    url = backOfficeUrl(task, shot, context._session);
                }
                result.url = url.replace(/_token=[^&]+/, '_token=…').replace(/([?&])token=[^&]+/, '$1token=…');
                const page = await context.newPage();
                let response = await page.goto(url, { waitUntil: 'load', timeout: 240000 });
                // Dev mode answers a non-canonical URL with a "[Debug] This page has moved" page instead of a redirect.
                for (let hop = 0; hop < 3; hop++) {
                    const moved = (await page.content()).match(/\[Debug\] This page has moved[\s\S]*?href="([^"]+)"/);
                    if (!moved) {
                        break;
                    }
                    response = await page.goto(decode(moved[1]), { waitUntil: 'load', timeout: 240000 });
                }
                await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
                const status = response ? response.status() : 0;
                const html = await page.content();
                if (status >= 400) {
                    throw new Error('HTTP ' + status + ' for ' + result.url);
                }
                for (const needle of ERROR_NEEDLES) {
                    if (html.includes(needle)) {
                        throw new Error('The page contains "' + needle + '" (' + result.url + ')');
                    }
                }
                if (shot.area === 'bo' && /controller=AdminLogin|id="login_form"|name="passwd"/.test(page.url() + html) && !/_token=/.test(page.url())) {
                    throw new Error('Not logged in on ' + result.url);
                }
                await page.addStyleTag({ content: hide.join(',') + '{display:none !important;visibility:hidden !important}' });
                for (const selector of shot.click || []) {
                    await page.locator(selector).first().click({ timeout: 15000 });
                    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
                }
                if (shot.wait_for) {
                    await page.locator(shot.wait_for).first().waitFor({ state: 'visible', timeout: 30000 });
                }
                await page.evaluate(() => document.fonts.ready);
                await page.waitForTimeout(shot.delay || 600);
                fs.mkdirSync(path.dirname(shot.out), { recursive: true });
                if (shot.selector) {
                    const locator = page.locator(shot.selector).first();
                    if ((await locator.count()) === 0) {
                        throw new Error('Selector "' + shot.selector + '" not found on ' + result.url);
                    }
                    await locator.scrollIntoViewIfNeeded();
                    const box = await locator.evaluate((el) => {
                        const r = el.getBoundingClientRect();
                        return { x: r.left + window.scrollX, y: r.top + window.scrollY, width: r.width, height: r.height };
                    });
                    const pad = shot.padding === undefined ? 16 : shot.padding;
                    const size = await page.evaluate(() => ({ w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight }));
                    const x = Math.max(0, box.x - pad);
                    const y = Math.max(0, box.y - pad);
                    await page.screenshot({
                        path: shot.out,
                        fullPage: true,
                        clip: { x, y, width: Math.min(size.w - x, box.width + 2 * pad), height: Math.min(size.h - y, box.height + 2 * pad) },
                    });
                } else {
                    if (shot.scroll_to) {
                        const target = page.locator(shot.scroll_to).first();
                        if ((await target.count()) === 0) {
                            throw new Error('scroll_to selector "' + shot.scroll_to + '" not found on ' + result.url);
                        }
                        if (shot.scroll_offset === undefined) {
                            // Centre the element: a sticky header (Hummingbird) would cover it at the top.
                            await target.evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'instant' }));
                        } else {
                            await target.evaluate((el, off) => window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - off)), shot.scroll_offset);
                        }
                        await page.waitForTimeout(300);
                    } else {
                        await page.evaluate(() => window.scrollTo(0, 0));
                    }
                    await page.screenshot({ path: shot.out, fullPage: !!shot.full_page });
                }
                result.ok = true;
                await page.close();
            } catch (e) {
                result.error = String(e && e.message ? e.message : e).split('\n')[0];
            }
        }
    } finally {
        for (const context of Object.values(contexts)) {
            await context.close();
        }
        proxy.forEach((server) => server.close());
    }
}

/**
 * Prints HTML files to PDF (print media, CSS page size and page-margin boxes for page numbers).
 */
async function pdf(browser, task, results) {
    const context = await browser.newContext();
    for (const item of task.items) {
        const result = { task: 'pdf', id: item.out, ok: false, error: '', out: item.out };
        results.push(result);
        try {
            const page = await context.newPage();
            await page.emulateMedia({ media: 'print' });
            await page.goto('file://' + item.html, { waitUntil: 'load', timeout: 120000 });
            await page.evaluate(() => document.fonts.ready);
            await page.pdf({ path: item.out, printBackground: true, preferCSSPageSize: true, format: item.format || 'A4', tagged: true, outline: true });
            await page.close();
            result.ok = true;
        } catch (e) {
            result.error = String(e && e.message ? e.message : e).split('\n')[0];
        }
    }
    await context.close();
}

/**
 * Renders SVG files to square PNG files (logo suggestion).
 */
async function svgPng(browser, task, results) {
    for (const item of task.items) {
        const result = { task: 'svg-png', id: item.out, ok: false, error: '', out: item.out };
        results.push(result);
        try {
            const size = item.size || 512;
            const context = await browser.newContext({ viewport: { width: size, height: size } });
            const page = await context.newPage();
            const svg = fs.readFileSync(item.in).toString('base64');
            await page.setContent('<html><body style="margin:0;background:transparent"><img style="width:' + size + 'px;height:' + size + 'px;display:block" src="data:image/svg+xml;base64,' + svg + '"></body></html>');
            await page.screenshot({ path: item.out, omitBackground: true });
            await context.close();
            result.ok = true;
        } catch (e) {
            result.error = String(e && e.message ? e.message : e).split('\n')[0];
        }
    }
}

/**
 * Runs every task of the job and writes the result file next to it.
 */
async function main() {
    const jobFile = process.argv[2];
    const job = JSON.parse(fs.readFileSync(jobFile, 'utf8'));
    const results = [];
    const browser = await chromium.launch({ args: ['--font-render-hinting=none', '--disable-dev-shm-usage'] });
    try {
        for (const task of job.tasks) {
            try {
                if (task.type === 'screenshots') {
                    await screenshots(browser, task, results);
                } else if (task.type === 'pdf') {
                    await pdf(browser, task, results);
                } else if (task.type === 'svg-png') {
                    await svgPng(browser, task, results);
                } else {
                    results.push({ task: task.type, ok: false, error: 'Unknown task type' });
                }
            } catch (e) {
                results.push({ task: task.type, ok: false, error: String(e && e.message ? e.message : e).split('\n')[0] });
            }
        }
    } finally {
        await browser.close();
    }
    fs.writeFileSync(path.join(path.dirname(jobFile), 'result.json'), JSON.stringify(results, null, 2));
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
