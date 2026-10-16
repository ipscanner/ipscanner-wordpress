# IPScanner for WordPress

WordPress plugin that checks visitors with [IPScanner](https://ipscanner.io) and blocks bots, Tor, VPN and proxy traffic on login, registration, password reset, comments, WooCommerce checkout and registration, Contact Form 7 and WPForms, and optionally the whole front end.

## Install

Install [IPScanner – Block Bots, VPNs & Proxies](https://wordpress.org/plugins/ipscanner-bot-blocker/) from Plugins > Add New. Go to Settings > IPScanner, click Connect with IPScanner, sign up or sign in in the popup and choose the protected areas. Pasting an API key and Site ID still works.

Requires WordPress 6.0+ and PHP 7.4+.

## How it works

| | |
|---|---|
| Classes | `human`, `verified_bot`, `ai_agent`, `malicious_automation`, `tor`, `vpn`, `proxy`, `hosting`, `relay` |
| Without Site ID | Mode (monitor or enforce) and blocked classes come from the settings page. The network class counts too, so blocking `vpn` also blocks a bot on a VPN. |
| With Site ID | Mode and per class policy (`allow`, `flag`, `block`) come from `GET /v1/sites/{id}/policy`, cached 30 s, last good policy kept for 24 h if a refresh fails. |
| Always pass | Verified crawlers, administrators (optional, on by default), the allowlist (IPs and CIDR ranges, v4 and v6), private addresses. |
| Cache | One check per visitor (site, IP, user agent) per 10 minutes, in a transient. |
| Fail open | Timeout 1.5 s by default. On a timeout or error the visitor is allowed and checks pause for 30 s (5 minutes on 401, 403 or 429). |
| Blocked | Pages get a 403 page. Forms get an error message. Both use the configurable block message. |
| Visitor IP | `REMOTE_ADDR` by default. Cloudflare (`CF-Connecting-IP`) or `X-Forwarded-For` only from trusted proxies. |
| Connect | OAuth 2.0 authorization code with PKCE (S256). The popup opens `https://ipscanner.io/connect/wordpress`, comes back to `admin-post.php?action=ipscanner_connect_callback`, and the plugin swaps the code server to server at `POST /api/connect/wordpress/token` for an API key named `WordPress · <host>` and a Site ID. |
| Usage stats | Opt in (on after Connect): admin actions and a daily summary to `POST /api/wordpress/events`, no visitor data. |
| Debug | With `WP_DEBUG`, front end responses carry `X-IPScanner-Status`, `X-IPScanner-Class` and `X-IPScanner-Action`. |

Requests send `X-IPScanner-Source: wordpress` and `User-Agent: ipscanner-wordpress/<version>`.

Constants for `wp-config.php`:

```php
define( 'IPSCANNER_API_KEY', 'pk_live_...' );           // overrides the saved key
define( 'IPSCANNER_API_BASE', 'http://localhost:3050' ); // testing only
define( 'IPSCANNER_APP_BASE', 'http://localhost:3000' ); // testing only: connect and usage stats
```

Filters: `ipscanner_skip` (bool, ip, area) and `ipscanner_decision` (result, verdict, area).

## Develop

```sh
composer install
composer test            # PHPUnit
composer lint            # PHPCS, WordPress standard
npm install
npx wp-env start         # http://localhost:8888, admin / password
IPSCANNER_KEY_FILE=/path/to/key npm run test:e2e
npm run build            # build/ipscanner.zip
```

The end to end suite drives a wp-env site with Playwright. Visitors are simulated with `CF-Connecting-IP` from the Docker gateway (172.18.0.1), so it needs an API that classifies those addresses: point `IPSCANNER_API_BASE` at it in `.wp-env.override.json`.

## License

GPL-2.0-or-later
