// End to end checks against a wp-env site and a reachable IPScanner API.
//
//   IPSCANNER_KEY_FILE   file holding an API key (required)
//   SITE_HELPER          optional CommonJS module with { siteId, setPolicy(policy, mode) }
//                        that edits a dashboard site; enables the site policy scenarios
//   API_STOP             optional shell command that stops the API (fail open scenario)
//   SHOTS                screenshot directory (default ./test-results)

import { execFileSync, execSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import http from 'node:http';
import { chromium } from 'playwright';

const URL_BASE = process.env.WP_URL || 'http://localhost:8888';
const SHOTS = process.env.SHOTS || 'test-results';
const KEY = readFileSync(process.env.IPSCANNER_KEY_FILE, 'utf8').trim();
const CONTAINERS = execSync("docker ps --format '{{.Names}}'", { encoding: 'utf8' }).split('\n');
const CONTAINER = CONTAINERS.find((n) => n.startsWith('wp-env-ipscanner') && n.endsWith('-wordpress-1'));
const CLI = CONTAINERS.find((n) => n.startsWith('wp-env-ipscanner') && n.endsWith('-cli-1')) || CONTAINER;
const PROXY = process.env.PROXY_IP || '172.18.0.1';
mkdirSync(SHOTS, { recursive: true });

const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const HEADLESS = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/141.0.0.0 Safari/537.36';
const P = {
	tor: { ip: '185.220.101.1', ua: CHROME },
	vpn: { ip: '185.217.69.166', ua: CHROME },
	hosting: { ip: '5.9.0.1', ua: CHROME },
	residential: { ip: '84.128.0.1', ua: CHROME },
	headless: { ip: '84.128.0.1', ua: HEADLESS },
};
const MESSAGE = 'Your request was blocked. If you think this is a mistake, contact the site owner.';

function wp(...args) {
	return execFileSync('docker', ['exec', '-u', '501', '-e', 'HOME=/tmp', '-w', '/var/www/html', CLI, 'wp', ...args], {
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	}).trim();
}

function settings() {
	try {
		return JSON.parse(wp('option', 'get', 'ipscanner_settings', '--format=json'));
	} catch {
		return {};
	}
}

function setSettings(patch) {
	wp('option', 'update', 'ipscanner_settings', JSON.stringify({ ...settings(), ...patch }), '--format=json');
}

function reset() {
	wp('transient', 'delete', '--all');
	wp('eval', 'delete_option("ipscanner_log"); delete_option("ipscanner_stats"); delete_option("ipscanner_policy_cache"); delete_option("ipscanner_last_error");');
}

function log() {
	return JSON.parse(wp('eval', 'echo wp_json_encode( get_option( "ipscanner_log", array() ) );') || '[]');
}

const results = [];
function record(scenario, expected, actual) {
	const pass = String(actual).includes(String(expected));
	const shown = String(actual).length > 160 ? (pass ? 'page containing it' : String(actual).replace(/\s+/g, ' ').slice(0, 160)) : String(actual);
	results.push({ scenario, expected: String(expected), actual: shown, pass });
	console.log(`${pass ? 'PASS' : 'FAIL'}  ${scenario}: expected ${expected}, got ${shown}`);
}

const browser = await chromium.launch();

async function as(persona, header = 'CF-Connecting-IP') {
	const p = P[persona];
	const headers = header === 'X-Forwarded-For' ? { 'X-Forwarded-For': `1.1.1.1, ${p.ip}` } : { [header]: p.ip };
	return browser.newContext({ userAgent: p.ua, extraHTTPHeaders: headers, baseURL: URL_BASE });
}

async function visit(persona, path = '/', header) {
	const ctx = await as(persona, header);
	const page = await ctx.newPage();
	const t0 = Date.now();
	const res = await page.goto(path);
	const out = {
		status: res.status(),
		action: res.headers()['x-ipscanner-action'] || '',
		body: await page.content(),
		ms: Date.now() - t0,
		page,
		ctx,
	};
	return out;
}

async function login(persona, user, pass) {
	const ctx = await as(persona);
	const page = await ctx.newPage();
	await page.goto('/wp-login.php');
	await page.fill('#user_login', user);
	await page.fill('#user_pass', pass);
	await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
	await page.waitForLoadState('domcontentloaded');
	const error = (await page.locator('#login_error').textContent().catch(() => '')) || '';
	const url = page.url();
	const outcome = error.trim() || (url.includes('wp-login.php') ? `still on ${url}` : 'logged in');
	return { page, ctx, url, error: error.trim(), outcome };
}

async function save(page) {
	await Promise.all([page.waitForResponse((r) => r.url().includes('options.php') && r.request().method() === 'POST'), page.click('#submit')]);
	await page.waitForLoadState('load');
	await page.waitForSelector('#ipscanner-api-key, #wpbody-content');
}

async function adminContext() {
	const ctx = await browser.newContext({ baseURL: URL_BASE, viewport: { width: 1280, height: 2100 } });
	const page = await ctx.newPage();
	await page.goto('/wp-login.php');
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'password');
	await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
	return { ctx, page };
}

function clearDebugLog() {
	execFileSync('docker', ['exec', CONTAINER, 'sh', '-c', ': > /var/www/html/wp-content/debug.log']);
}

function debugLog() {
	return execFileSync('docker', ['exec', CONTAINER, 'cat', '/var/www/html/wp-content/debug.log'], { encoding: 'utf8' });
}

function phpIssues(text) {
	return text
		.split('\n')
		.filter((l) => /PHP (Warning|Notice|Deprecated|Fatal|Parse)/.test(l) && /ipscanner/i.test(l));
}

// Fixtures.
wp('option', 'update', 'users_can_register', '1');
try {
	wp('user', 'create', 'e2esub', 'e2esub@example.test', '--role=subscriber', '--user_pass=Sub-pass-123');
} catch {}
wp('option', 'update', 'woocommerce_cod_settings', JSON.stringify({ enabled: 'yes', title: 'Cash on delivery' }), '--format=json');
wp('option', 'update', 'woocommerce_coming_soon', 'no');
let productId = wp('post', 'list', '--post_type=product', '--name=e2e-item', '--field=ID');
if (!productId) {
	productId = wp('post', 'create', '--post_type=product', '--post_title=E2E item', '--post_name=e2e-item', '--post_status=publish', '--porcelain');
	wp('post', 'meta', 'update', productId, '_regular_price', '10');
	wp('post', 'meta', 'update', productId, '_price', '10');
	wp('post', 'meta', 'update', productId, '_virtual', 'yes');
	wp('post', 'meta', 'update', productId, '_stock_status', 'instock');
}
const cf7Id = wp('post', 'list', '--post_type=wpcf7_contact_form', '--field=ID').split('\n')[0];
let cf7Page = wp('post', 'list', '--post_type=page', '--name=e2e-contact', '--field=ID');
if (!cf7Page) {
	cf7Page = wp('post', 'create', '--post_type=page', '--post_title=Contact', '--post_name=e2e-contact', '--post_status=publish', `--post_content=[contact-form-7 id="${cf7Id}"]`, '--porcelain');
}
const checkoutPage = wp('option', 'get', 'woocommerce_checkout_page_id');
const checkoutBlocks = wp('post', 'get', checkoutPage, '--field=post_content');

wp('option', 'delete', 'ipscanner_settings');
reset();
clearDebugLog();

// 1. No API key: everything works, nothing is called.
setSettings({ api_key: '', mode: 'enforce', areas: ['site', 'login', 'register', 'lostpassword', 'comments'], block_classes: ['tor'] });
{
	const r = await visit('tor');
	record('no key: tor visitor gets the home page', 200, r.status);
	const l = await login('tor', 'e2esub', 'Sub-pass-123');
	record('no key: tor visitor can log in', 'logged in', l.outcome);
	record('no key: no log entries', 0, log().length);
	await r.ctx.close();
	await l.ctx.close();
}

// 2. Onboarding: key, connection test and areas from the welcome card.
wp('option', 'delete', 'ipscanner_settings');
wp('option', 'delete', 'ipscanner_onboarding');
{
	const { ctx, page } = await adminContext();
	await page.goto('/wp-admin/options-general.php?page=ipscanner');
	record('onboarding: welcome card without a key', 'Connect with IPScanner', await page.locator('.ipscanner-welcome').textContent());
	record('onboarding: connect button starts the connect flow', 'true', String((await page.getAttribute('[data-ipscanner-connect]', 'href')).includes('action=ipscanner_connect')));
	await page.click('.ipscanner-paste summary');
	await page.fill('#ipscanner-onboard-key', 'pk_live_rejected');
	await Promise.all([page.waitForLoadState('load'), page.click('.ipscanner-inline-form button')]);
	record('onboarding: rejected key shown in the card', 'The API key was rejected.', await page.locator('.ipscanner-welcome').textContent());
	await page.click('.ipscanner-paste summary');
	await page.fill('#ipscanner-onboard-key', KEY);
	await Promise.all([page.waitForLoadState('load'), page.click('.ipscanner-inline-form button')]);
	record('onboarding: key connected', 'Connected', await page.locator('.ipscanner-step').nth(0).textContent());
	record('onboarding: key not in page source', 'false', String((await page.content()).includes(KEY)));
	record('onboarding: pasted key leaves usage stats off', 'false', String(Boolean(settings().share_usage)));
	await page.uncheck('input[name="areas[]"][value="comments"]');
	await Promise.all([page.waitForLoadState('load'), page.click('.ipscanner-step.is-current button[type=submit]')]);
	record('onboarding: overview after the last step', 'Connected', await page.locator('.ipscanner-status').textContent());
	const saved = settings();
	record('onboarding: comments unchecked', 'false', String(saved.areas.includes('comments')));
	record('onboarding: other areas kept', 'true', String(saved.areas.includes('lostpassword') && saved.areas.includes('forms')));
	await ctx.close();
}

// 3. Settings page: save key and proxy settings through the form, test the connection.
wp('option', 'delete', 'ipscanner_settings');
{
	const { ctx, page } = await adminContext();
	await page.goto('/wp-admin/options-general.php?page=ipscanner&tab=settings');
	await page.fill('#ipscanner-api-key', KEY);
	await page.selectOption('#ipscanner-ip-source', 'cloudflare');
	await page.fill('#ipscanner-trusted', `${PROXY}\nnot-an-ip`);
	await page.check('input[name="ipscanner_settings[areas][]"][value="site"]');
	for (const c of ['tor', 'vpn']) await page.check(`input[name="ipscanner_settings[block_classes][]"][value="${c}"]`);
	await save(page);
	const notices = await page.locator('.notice, .error, .updated').allTextContents();
	record('settings: invalid proxy entry reported', 'not-an-ip', notices.join(' | '));
	await page.fill('#ipscanner-trusted', PROXY);
	await save(page);
	record('settings: stays on the settings tab', 'tab=settings', page.url());
	record('settings: saved', 'Settings saved', (await page.locator('.notice, .updated').allTextContents()).join(' | '));
	const saved = settings();
	record('settings: key stored', 'true', String(saved.api_key === KEY));
	record('settings: key not in page source', 'false', String((await page.content()).includes(KEY)));
	record('settings: trusted proxies cleaned', PROXY, JSON.stringify(saved.trusted_proxies));
	await Promise.all([page.waitForLoadState('load'), page.click('text=Test connection')]);
	await page.waitForSelector('.notice-success, .notice-error');
	record('settings: test connection', 'Connected. Plan:', (await page.locator('.notice-success, .notice-error').allTextContents()).join(' | '));
	await page.waitForLoadState('networkidle');
	await page.waitForTimeout(500);
	await page.screenshot({ path: `${SHOTS}/settings.png` });
	await ctx.close();
}

// 4. Monitor mode records but never blocks.
reset();
const expectMonitor = { tor: 'would_block', vpn: 'would_block', hosting: 'allow', residential: 'allow', headless: 'would_block' };
for (const [persona, action] of Object.entries(expectMonitor)) {
	const r = await visit(persona);
	record(`monitor: ${persona} status`, 200, r.status);
	record(`monitor: ${persona} X-IPScanner-Action`, action, r.action);
	await r.ctx.close();
}
record('monitor: decisions logged', 5, log().length);

// 5. Enforce mode blocks the selected classes with the 403 page.
setSettings({ mode: 'enforce', block_classes: ['malicious_automation', 'tor', 'vpn'] });
const expectEnforce = { tor: 403, vpn: 403, hosting: 200, residential: 200, headless: 403 };
for (const [persona, status] of Object.entries(expectEnforce)) {
	const r = await visit(persona);
	record(`enforce: ${persona} status`, status, r.status);
	if (persona === 'tor') {
		record('enforce: block page message', MESSAGE, r.body);
		record('enforce: no credit link by default', 'false', String(r.body.includes('Protected by IPScanner')));
		await r.page.screenshot({ path: `${SHOTS}/block-page.png` });
	}
	await r.ctx.close();
}
{
	const r = await visit('tor', '/wp-content/uploads/missing.css');
	record('enforce: missing static file gets the normal 404, not a check', 404, r.status);
	await r.ctx.close();
}
setSettings({ block_message: 'Custom block text.', show_credit: true });
{
	const r = await visit('tor');
	record('enforce: custom message', 'Custom block text.', r.body);
	record('enforce: credit shown when turned on', 'Protected by IPScanner', r.body);
	await r.ctx.close();
}
setSettings({ block_message: '', show_credit: false });
{
	const { ctx, page } = await adminContext();
	await page.goto('/wp-admin/options-general.php?page=ipscanner');
	const numbers = (await page.locator('.ipscanner-number').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
	record('overview: last 24 hours', 'Blocked', numbers.join(' | '));
	record('overview: recent decisions', 'true', String((await page.locator('.ipscanner-log tbody tr').count()) > 0));
	await ctx.close();
}

// 6. Spoofing: proxy headers are ignored unless the peer is trusted.
setSettings({ ip_source: 'remote_addr' });
{
	const r = await visit('tor');
	record('ip source REMOTE_ADDR: CF-Connecting-IP ignored', 200, r.status);
	await r.ctx.close();
}
setSettings({ ip_source: 'x_forwarded_for' });
{
	const r = await visit('tor', '/', 'X-Forwarded-For');
	record('ip source X-Forwarded-For: tor resolved and blocked', 403, r.status);
	await r.ctx.close();
}
setSettings({ ip_source: 'cloudflare', trusted_proxies: '' });
{
	const r = await visit('tor');
	record('cloudflare source from untrusted peer: header ignored', 200, r.status);
	await r.ctx.close();
}
setSettings({ trusted_proxies: PROXY });

// 7. Allowlist and administrators.
setSettings({ allowlist: '185.220.101.0/24' });
{
	const r = await visit('tor');
	record('allowlist: tor in allowlisted range passes', 200, r.status);
	await r.ctx.close();
}
setSettings({ allowlist: '' });
{
	const ctx = await as('tor');
	const page = await ctx.newPage();
	await page.goto('/wp-login.php');
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'password');
	await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
	record('admin: login from tor with valid password', 'wp-admin', page.url());
	const res = await page.goto('/');
	record('admin: logged in admin browses from tor', 200, res.status());
	await ctx.close();
}
setSettings({ allow_admins: false });
{
	const l = await login('tor', 'admin', 'password');
	record('admin: blocked when "always allow administrators" is off', MESSAGE, l.error);
	await l.ctx.close();
}
setSettings({ allow_admins: true });

// 8. Forms: errors instead of a 403.
setSettings({ areas: ['login', 'register', 'lostpassword', 'comments', 'woo_checkout', 'woo_register', 'forms'] });
{
	const l = await login('tor', 'e2esub', 'Sub-pass-123');
	record('login: tor blocked with message', MESSAGE, l.error);
	await l.page.screenshot({ path: `${SHOTS}/login-error.png` });
	await l.ctx.close();
	const h = await login('headless', 'e2esub', 'Sub-pass-123');
	record('login: headless browser blocked', MESSAGE, h.error);
	await h.ctx.close();
	const ok = await login('residential', 'e2esub', 'Sub-pass-123');
	record('login: residential allowed', 'logged in', ok.outcome);
	await ok.ctx.close();
	const host = await login('hosting', 'e2esub', 'Sub-pass-123');
	record('login: hosting allowed (class not selected)', 'logged in', host.outcome);
	await host.ctx.close();
}
for (const [persona, expected] of [['tor', MESSAGE], ['residential', 'checkemail=registered']]) {
	const ctx = await as(persona);
	const page = await ctx.newPage();
	await page.goto('/wp-login.php?action=register');
	const name = `e2e${persona}${Date.now()}`;
	await page.fill('#user_login', name);
	await page.fill('#user_email', `${name}@example.test`);
	await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
	const err = (await page.locator('#login_error').textContent().catch(() => '')) || '';
	record(`register: ${persona}`, expected, persona === 'tor' ? err.trim() : page.url());
	await ctx.close();
}
{
	const ctx = await as('vpn');
	const page = await ctx.newPage();
	await page.goto('/wp-login.php?action=lostpassword');
	await page.fill('#user_login', 'e2esub');
	await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
	const err = (await page.locator('#login_error').textContent().catch(() => '')) || '';
	record('lost password: vpn blocked', MESSAGE, err.trim());
	await ctx.close();
}
for (const [persona, expected] of [['tor', 403], ['residential', 302]]) {
	const ctx = await as(persona);
	const page = await ctx.newPage();
	await page.goto('/?p=1');
	await page.fill('#comment', `Comment from ${persona} ${Date.now()}`);
	await page.fill('#author', persona);
	await page.fill('#email', `${persona}@example.test`);
	const [res] = await Promise.all([page.waitForResponse((r) => r.url().includes('wp-comments-post.php')), page.click('#submit')]);
	await page.waitForLoadState('domcontentloaded');
	record(`comment: ${persona} status`, expected, res.status());
	if (persona === 'tor') {
		record('comment: tor sees message', MESSAGE, await page.content());
		await page.screenshot({ path: `${SHOTS}/comment-error.png` });
	}
	await ctx.close();
}

// WooCommerce checkout, block (Store API) and classic.
async function checkout(persona) {
	const ctx = await as(persona);
	const page = await ctx.newPage();
	await page.goto(`/?add-to-cart=${productId}`);
	await page.goto('/checkout/');
	const classic = (await page.locator('form.woocommerce-checkout').count()) > 0;
	let text;
	if (classic) {
		await page.fill('#billing_first_name', 'Test');
		await page.fill('#billing_last_name', persona);
		await page.fill('#billing_email', `${persona}@example.test`);
		await page.fill('#billing_address_1', '1 Main St').catch(() => {});
		await page.fill('#billing_city', 'Berlin').catch(() => {});
		await page.fill('#billing_postcode', '10115').catch(() => {});
		await page.fill('#billing_phone', '123456').catch(() => {});
		await page.click('#place_order');
		await page.waitForSelector('.woocommerce-error, .woocommerce-NoticeGroup-checkout, .woocommerce-order-received, .woocommerce-thankyou-order-received', { timeout: 20000 }).catch(() => {});
		await page.waitForTimeout(1000);
	} else {
		await page.fill('#email', `${persona}@example.test`);
		for (const [sel, val] of [['#billing-first_name', 'Test'], ['#billing-last_name', persona], ['#billing-address_1', '1 Main St'], ['#billing-city', 'Berlin'], ['#billing-postcode', '10115']]) {
			await page.fill(sel, val).catch(() => {});
		}
		await page.click('.wc-block-components-checkout-place-order-button');
		await Promise.race([
			page.waitForURL(/order-received/, { timeout: 20000 }),
			page.waitForSelector('.wc-block-components-notice-banner.is-error', { timeout: 20000 }),
		]).catch(() => {});
		await page.waitForTimeout(500);
	}
	text = page.url().includes('order-received') ? `order received ${page.url()}` : await page.locator('body').innerText();
	await ctx.close();
	return { classic, text };
}
for (const mode of ['block', 'classic']) {
	if (mode === 'classic') wp('post', 'update', checkoutPage, '--post_content=[woocommerce_checkout]');
	for (const [persona, expected] of [['tor', MESSAGE], ['residential', 'order received']]) {
		const r = await checkout(persona);
		record(`woo ${mode} checkout (${r.classic ? 'classic' : 'block'} form): ${persona}`, expected, r.text.slice(0, 300).replace(/\s+/g, ' '));
	}
}
wp('post', 'update', checkoutPage, `--post_content=${checkoutBlocks}`);

// Contact Form 7.
for (const [persona, expected] of [['tor', 'spam'], ['residential', 'mail_']]) {
	const ctx = await as(persona);
	const page = await ctx.newPage();
	await page.goto(`/?page_id=${cf7Page}`);
	await page.fill('input[name="your-name"]', persona);
	await page.fill('input[name="your-email"]', `${persona}@example.test`);
	await page.fill('input[name="your-subject"]', 'Hi').catch(() => {});
	await page.fill('textarea[name="your-message"]', 'Hello');
	const [res] = await Promise.all([page.waitForResponse((r) => r.url().includes('/feedback') && r.request().method() === 'POST'), page.click('input[type="submit"]')]);
	const json = await res.json();
	record(`contact form 7: ${persona}`, expected, json.status);
	await ctx.close();
}

// Monitor mode on forms: nothing blocked.
setSettings({ mode: 'monitor' });
{
	const l = await login('tor', 'e2esub', 'Sub-pass-123');
	record('monitor: tor login allowed', 'logged in', l.outcome);
	await l.ctx.close();
}

// 9. Site policy from the dashboard.
if (process.env.SITE_HELPER) {
	const helper = createRequire(import.meta.url)(process.env.SITE_HELPER);
	await helper.setPolicy({ malicious_automation: 'block', tor: 'block' }, 'enforce');
	setSettings({ site_id: helper.siteId, mode: 'monitor', block_classes: ['hosting'], areas: ['site'], timeout: 3 });
	reset();
	for (const [persona, status] of Object.entries({ tor: 403, vpn: 200, hosting: 200, headless: 403, residential: 200 })) {
		const r = await visit(persona);
		record(`site policy: ${persona}`, status, r.status);
		await r.ctx.close();
	}
	const version = await helper.setPolicy({ malicious_automation: 'block', tor: 'block', vpn: 'block' }, 'enforce');
	const t0 = Date.now();
	let status = 200;
	while (Date.now() - t0 < 60000) {
		const r = await visit('vpn');
		status = r.status;
		await r.ctx.close();
		if (status === 403) break;
		await new Promise((res) => setTimeout(res, 3000));
	}
	record(`site policy: vpn blocked after dashboard change (v${version})`, 403, status);
	record('site policy: change applied within 35 s', 'true', String(Date.now() - t0 <= 35000) + ` (${Math.round((Date.now() - t0) / 1000)} s)`);
	await helper.setPolicy({ malicious_automation: 'block', tor: 'block', vpn: 'block' }, 'monitor');
	const t1 = Date.now();
	while (Date.now() - t1 < 60000) {
		const r = await visit('tor');
		status = r.status;
		await r.ctx.close();
		if (status === 200) break;
		await new Promise((res) => setTimeout(res, 3000));
	}
	record('site policy: dashboard monitor mode stops blocking', 200, status);
	{
		const { ctx, page } = await adminContext();
		await page.goto('/wp-admin/options-general.php?page=ipscanner&tab=settings');
		await Promise.all([page.waitForLoadState('load'), page.click('text=Test connection')]);
		await page.waitForSelector('.notice-success, .notice-error');
		record('site policy: test connection shows site', helper.siteId, (await page.locator('.notice-success, .notice-error').allTextContents()).join(' | '));
		await ctx.close();
	}
	await helper.setPolicy({ malicious_automation: 'block', tor: 'block' }, 'enforce');
	setSettings({ site_id: '' });
}

// 10. Slow API: the timeout applies and the visitor is let through.
const slow = http.createServer((req, res) => setTimeout(() => res.end('{}'), 5000)).listen(3059);
const realBase = wp('config', 'get', 'IPSCANNER_API_BASE');
wp('config', 'set', 'IPSCANNER_API_BASE', 'http://host.docker.internal:3059');
setSettings({ mode: 'enforce', block_classes: ['tor'], areas: ['site'], timeout: 1.5 });
reset();
{
	const r = await visit('tor');
	record('slow API: tor allowed (fail open)', 200, r.status);
	record('slow API: answered within timeout + 1.5 s', 'true', `${r.ms < 3000} (${r.ms} ms)`);
	await r.ctx.close();
	const again = await visit('tor');
	record('slow API: backoff skips the next call', 'true', `${again.ms < 1000} (${again.ms} ms)`);
	await again.ctx.close();
	record('slow API: logged as timeout', 'timeout', log()[0]?.status);
}
wp('config', 'set', 'IPSCANNER_API_BASE', realBase);
slow.close();

// 11. API down.
if (process.env.API_STOP) {
	execSync(process.env.API_STOP);
	reset();
	const r = await visit('tor');
	record('API down: tor allowed (fail open)', 200, r.status);
	record('API down: logged as error', 'error', log()[0]?.status);
	await r.ctx.close();
}

// Dashboard widget screenshot.
{
	const { ctx, page } = await adminContext();
	await page.goto('/wp-admin/');
	await page.locator('#ipscanner').screenshot({ path: `${SHOTS}/dashboard-widget.png` }).catch(() => {});
	await ctx.close();
}

const issues = phpIssues(debugLog());
record('debug.log: no PHP notices from the plugin', 0, issues.length + (issues.length ? `: ${issues[0]}` : ''));

await browser.close();
const failed = results.filter((r) => !r.pass);
console.log(`\n${results.length - failed.length}/${results.length} passed`);
process.exit(failed.length ? 1 : 0);
