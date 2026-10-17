=== IPScanner – Block Bots, VPNs & Proxies ===
Contributors: ipscanner
Tags: captcha, anti-spam, bot protection, vpn blocker, registration spam
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A CAPTCHA alternative with no puzzle. Stops fake signups, spam registrations, form spam and card-testing bots, plus VPN, proxy and Tor traffic.

== Description ==

IPScanner checks each visitor on the server and blocks bots before they reach your forms. Real people never see a challenge.

**What it stops**

* Fake signups and spam registrations on WordPress and WooCommerce
* Comment spam and contact form spam
* Card-testing bots on WooCommerce checkout
* Login attacks from bots, Tor and proxies
* VPN and proxy traffic where you don't want it

IPScanner checks each visitor with the IPScanner API and sorts them into one class: human, verified crawler, AI agent, malicious automation, Tor, VPN, proxy, hosting or privacy relay. You choose which classes to block and where.

**Where it checks**

* Login, registration and password reset
* Comments
* WooCommerce checkout (classic and block checkout) and account registration
* Contact Form 7 and WPForms submissions
* The whole front end (optional, off by default)

Forms get an error message. Pages get a short 403 page.

**How it decides**

* Monitor mode records decisions and blocks nothing. Start here.
* Enforce mode blocks the classes you select. The default is malicious automation only.
* With a Site ID from the IPScanner dashboard, mode and per class policy (allow, flag, block) come from the dashboard, and changes apply within 30 seconds.
* Verified search engine and AI crawlers always pass.
* Administrators and the IP addresses or ranges you list are never blocked.

**What it costs**

Each new visitor check uses 2 requests from your IPScanner allowance. A visitor is checked at most once every 10 minutes. The whole front end check skips static files, the admin area, AJAX, cron and REST requests. Logins, forms and checkout are still checked when they are sent that way, including the WooCommerce block checkout (Store API).

**Fail open**

If the API is slow (1.5 second timeout by default), unreachable or out of allowance, visitors are allowed and checks pause for a short while.

**Behind a proxy**

Choose Cloudflare or X-Forwarded-For under Visitor IP. Proxy headers are only read from trusted proxy addresses (Cloudflare ranges are built in), so visitors cannot fake their address.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings > IPScanner and click Connect with IPScanner. Sign up or sign in in the window that opens and click Connect. The plugin gets an API key and a dashboard site for this WordPress site.
3. Choose what to protect.
4. Leave the mode on Monitor for a few days, check the Overview tab, then switch to Enforce.

You can also paste an API key from [ipscanner.io](https://ipscanner.io/dashboard/apikeys) instead of connecting.

The key can also be set in wp-config.php with `define( 'IPSCANNER_API_KEY', 'pk_live_...' );`.

== Frequently Asked Questions ==

= Is it a CAPTCHA? =

No. Visitors never solve a puzzle. The check runs on your server when a form is sent, and blocked visitors get an error message.

= Do I need an account? =

Yes. Connect with IPScanner creates a free one in a few clicks, or signs you in to an existing one. Any plan works. On the Free plan, sites managed from the dashboard run in monitor mode.

= Will it block Google or Bing? =

No. Verified search engine and AI crawlers always pass.

= What happens if IPScanner is down? =

Visitors are allowed. The plugin waits at most the configured timeout, then pauses checks for 30 seconds.

= I locked myself out. =

Administrators who log in with the right password are let through while "Always allow administrators" is on. You can also add your IP to Always allow, or deactivate the plugin by renaming its folder.

= Does it work with page caching? =

Login, registration, comments, checkout and forms are not cached, so those checks always run. Whole site protection only runs for requests that reach WordPress, so pages served from a full page cache are not checked.

= Does it slow my site down? =

A first visit waits for one API call (usually well under a second). Repeat visits within 10 minutes use the cached result.

== External services ==

This plugin connects to the IPScanner API at https://ipscanner.io, run by IPScanner. It is required for the plugin to work.

* When a protected form is submitted, or a page is viewed with whole site protection on, the plugin sends the visitor's IP address, user agent and these request headers: Accept, Accept-Language, Accept-Encoding, Sec-CH-UA and Sec-Fetch headers. It also sends a random request ID and your Site ID when one is set. Endpoint: `POST /v1/edge/check`. Results are cached on your site for 10 minutes per visitor.
* With a Site ID set, the plugin reads the site's policy (no visitor data) at most every 30 seconds. Endpoint: `GET /v1/sites/{site}/policy`.
* When an administrator opens Settings > IPScanner (at most every 5 minutes) or clicks Test connection, the plugin reads the plan and usage of the API key (no visitor data). Endpoint: `GET /v1/usage/summary`.
* Connect with IPScanner opens https://ipscanner.io/connect/wordpress with this site's admin URL, home URL, name, plugin and WordPress versions. After you approve, the plugin exchanges a one-time code for the API key and Site ID. Endpoint: `POST /api/connect/wordpress/token`.
* With "Send plugin usage stats" on (Settings > Advanced; turned on when you connect, since the connect window asks for it), the plugin sends usage events with the API key: connected or disconnected, settings changed (which settings, the mode, protected areas and blocked classes), connection tested, the settings page opened (at most twice a day per tab), plugin updated or deactivated, and once a day the last 24 hours' totals (checked, blocked, top class) with the PHP and WordPress versions, locale and whether WooCommerce, Contact Form 7 or WPForms are active. No visitor data. Endpoint: `POST /api/wordpress/events`.

Nothing is sent before you connect or save an API key.

Terms of service: https://ipscanner.io/terms
Privacy policy: https://ipscanner.io/privacy

Source code: https://github.com/ipscanner/ipscanner-wordpress

== Screenshots ==

1. Getting started: connect your account, then choose what to protect.
2. Connect with IPScanner: sign up or sign in, no key to copy.
3. Overview: connection, last 24 hours and recent decisions.
4. Settings.
5. The block page a blocked visitor sees.
6. A blocked login attempt.

== Changelog ==

= 0.2.0 =
* Connect with IPScanner: sign up or sign in in a popup instead of copying an API key. Connecting also adds the site to your IPScanner dashboard.
* Disconnect button under Settings.
* Optional usage stats (Settings > Advanced).

= 0.1.3 =
* Plugin renamed to "IPScanner – Block Bots, VPNs & Proxies".

= 0.1.2 =
* Overview tab with connection status, the last 24 hours and recent decisions.
* Getting started card for new sites.
* Settings grouped into sections.
* The "Protected by IPScanner" link on the block page is now off by default.

= 0.1.1 =
* API key link opens the API keys page.

= 0.1.0 =
* First release: visitor checks with monitor and enforce modes, dashboard site policy, login, registration, password reset, comment, WooCommerce, Contact Form 7 and WPForms protection, allowlist, trusted proxies, recent decisions.
