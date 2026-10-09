=== IPScanner ===
Contributors: ipscanner
Tags: security, bot protection, vpn, proxy, tor
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Block bots, Tor, VPN and proxy traffic on your site, login, registration, comments and WooCommerce checkout.

== Description ==

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

Each new visitor check uses 2 requests from your IPScanner allowance. A visitor is checked at most once every 10 minutes. Static files, the admin area, AJAX, cron and REST requests are never checked.

**Fail open**

If the API is slow (1.5 second timeout by default), unreachable or out of allowance, visitors are allowed and checks pause for a short while.

**Behind a proxy**

Choose Cloudflare or X-Forwarded-For under Visitor IP. Proxy headers are only read from trusted proxy addresses (Cloudflare ranges are built in), so visitors cannot fake their address.

== Installation ==

1. Install and activate the plugin.
2. Create an API key at [ipscanner.io](https://ipscanner.io/dashboard).
3. Go to Settings > IPScanner, paste the key and click Test connection.
4. Optional: create a site in the [IPScanner dashboard](https://ipscanner.io/dashboard/sites) and paste its Site ID.
5. Choose the protected areas. Leave the mode on Monitor for a few days, check Recent decisions, then switch to Enforce.

The key can also be set in wp-config.php with `define( 'IPSCANNER_API_KEY', 'pk_live_...' );`.

== Frequently Asked Questions ==

= Do I need an account? =

Yes. The plugin calls the IPScanner API with your API key. Any plan works. On the Free plan, sites managed from the dashboard run in monitor mode.

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

* When a protected form is submitted, or a page is viewed with whole site protection on, the plugin sends the visitor's IP address, user agent and these request headers: Accept, Accept-Language, Accept-Encoding, Sec-CH-UA and Sec-Fetch headers. It also sends your Site ID when one is set. Endpoint: `POST /v1/edge/check`. Results are cached on your site for 10 minutes per visitor.
* With a Site ID set, the plugin reads the site's policy (no visitor data) at most every 30 seconds. Endpoint: `GET /v1/sites/{site}/policy`.
* When an administrator clicks Test connection, the plugin reads the plan and usage of the API key. Endpoint: `GET /v1/usage/summary`.

Nothing is sent before an API key is saved.

Terms of service: https://ipscanner.io/terms
Privacy policy: https://ipscanner.io/privacy

== Screenshots ==

1. Settings > IPScanner.
2. The block page a blocked visitor sees.
3. A blocked login attempt.
4. Recent decisions on the dashboard.

== Changelog ==

= 0.1.0 =
* First release: visitor checks with monitor and enforce modes, dashboard site policy, login, registration, password reset, comment, WooCommerce, Contact Form 7 and WPForms protection, allowlist, trusted proxies, recent decisions.
