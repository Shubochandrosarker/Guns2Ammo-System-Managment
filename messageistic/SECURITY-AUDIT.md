# WordPress Plugin and Configuration Audit

Audit date: 2026-06-06

## Scope

This audit covers the Messageistic plugin source in this repository, including:

- plugin bootstrap, activation, deactivation, and uninstall behavior;
- WordPress capabilities, admin forms, REST routes, and webhook entry points;
- provider credential storage and settings updates;
- database access, imports, scheduled jobs, output escaping, and remote requests.

It does not include the hosting environment, the active WordPress installation, its users, other installed plugins/themes, web-server rules, TLS configuration, or production database contents. Those items must be checked in the deployed environment using the checklist below.

## Remediated findings

### High: production webhooks could accept unauthenticated requests

Twilio signature validation was controlled by an optional checkbox, and OtterText accepted all webhook requests when no shared secret was configured. A forged inbound request could create messages or change opt-in/opt-out state; a forged status callback could alter delivery state.

**Resolution:** Twilio now always requires the configured Auth Token and a valid `X-Twilio-Signature`. Route-only defaults are no longer injected into Twilio's signed form parameters. OtterText now always requires a configured webhook secret and a valid `X-OtterText-Signature`. Query-string secrets are no longer accepted because URLs are routinely retained in access logs and analytics systems. Authentication failures return HTTP 403 errors.

**Deployment action:** Confirm the public webhook URL seen by Twilio exactly matches the URL configured in Twilio, including scheme, host, path, port, and query string. Reverse proxies must preserve the public host and HTTPS state so signature reconstruction remains correct.

### High: saving one provider erased other providers' configuration

The WordPress Settings API replaces an option with the sanitizer's return value. The provider sanitizer built a new array containing only the submitted provider, so editing Twilio could delete OtterText and Testing settings (and vice versa).

**Resolution:** provider settings are now merged into the existing option. Only the submitted provider is updated.

### Medium: stored provider credentials were reflected into HTML

API keys, webhook secrets, and the Twilio Auth Token were populated into password input `value` attributes. Any browser extension, injected admin script, screenshot, or saved page could capture those credentials.

**Resolution:** secret fields render empty, indicate when a value is already stored, and preserve the stored credential when submitted blank.

## Positive controls observed

- Direct PHP access is blocked with `ABSPATH` guards.
- Administrative pages use dedicated capabilities and the Settings API supplies nonce checks for option updates.
- REST routes use explicit permission callbacks; only provider webhook routes are intentionally public.
- Most dynamic admin output is escaped for its HTML context.
- SQL values are generally parameterized, while dynamic table names are derived from the trusted WordPress table prefix.
- Uploaded imports use random server-side names and are placed behind Apache deny rules plus an `index.php` guard.
- Destructive uninstall behavior is opt-in.
- The Testing provider is the default, reducing accidental live sends immediately after activation.

## Remaining recommendations

### 1. Protect credentials at rest

Provider credentials are stored in the WordPress options table. WordPress does not encrypt option values by default. Restrict database access and backups, disable public database administration tools, rotate credentials after suspected compromise, and consider a deployment-specific secret provider or encryption layer whose key is held outside the database.

### 2. Add log retention and data minimization

Provider responses and message context can contain phone numbers, message bodies, and provider metadata. Define a retention period, purge expired logs/messages with a scheduled task, avoid logging credentials or authorization headers, and document retention in the site's privacy policy.

### 3. Harden import storage for non-Apache servers

The import directory writes `.htaccess`, which is ineffective on Nginx and some managed platforms. Configure the web server/CDN to deny `/wp-content/uploads/messageistic-imports/`, or move transient import files outside the public uploads tree. Add file-size and row-count limits appropriate to the host.

### 4. Add automated WordPress security and compatibility checks

The repository has no Composer development tooling or automated test suite. Add WordPress Coding Standards/PHPCS, PHPCompatibility, unit tests for settings merging and signature validation, and an integration test that exercises each REST permission callback. Run these checks in CI for every supported PHP and WordPress version.

### 5. Review capability assignment on every environment

Activation grants Messageistic capabilities to administrators only. Verify that no untrusted role or application-password user has `manage_messageistic`, `send_messageistic_sms`, or provider/settings capabilities. Treat `force` sending permission as privileged because it can bypass consent/opt-out enforcement in the sending service.

### 6. Validate production cron behavior

Campaigns, imports, synchronization, and provider health depend on WP-Cron. On low-traffic sites, disable request-driven WP-Cron and invoke `wp cron event run --due-now` from a real system scheduler. Monitor failed or stalled jobs.

### 7. Keep platform metadata current

The plugin metadata requires WordPress 6.2 and PHP 8.0, while `readme.txt` currently declares testing only through WordPress 6.5. Test against the actual production WordPress/PHP versions before deployment and update `Tested up to` only after that validation.

## Production WordPress checklist

Run these checks on the deployed site; they cannot be established from this repository alone:

1. Inventory active and inactive plugins/themes with versions and update status (`wp plugin list`, `wp theme list`). Remove unused extensions rather than merely deactivating them.
2. Verify WordPress core checksums (`wp core verify-checksums`) and plugin checksums where WordPress.org packages are used (`wp plugin verify-checksums --all --strict`).
3. Confirm HTTPS-only admin and webhook traffic, trusted proxy headers, HSTS, and no mixed-content webhook URL.
4. Confirm `DISALLOW_FILE_EDIT` is enabled and production debugging/display are disabled (`WP_DEBUG_DISPLAY=false`, `display_errors=Off`).
5. Review administrator accounts, application passwords, MFA coverage, stale sessions, and role capability assignments.
6. Restrict database and filesystem credentials; ensure `wp-config.php` is not web-readable and salts are unique.
7. Verify backups are encrypted, access-controlled, tested for restoration, and subject to retention rules.
8. Check web-server denial for import files and prevent PHP execution throughout uploads.
9. Rotate Twilio/OtterText credentials after initial deployment or any exposure, and send test signed inbound/status callbacks.
10. Confirm privacy notices, consent evidence, STOP/START handling, quiet hours, sending limits, and message retention meet applicable legal and carrier requirements.
