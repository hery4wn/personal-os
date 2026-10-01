# Personal OS

A private workspace for daily tasks, projects, goals, calendar, habits, ideas, notes, personal finance, content planning and weekly reviews. Built from the supplied Personal OS V1 PRD with Laravel 13, PHP 8.4, PostgreSQL, Vue 3, TypeScript, Inertia 2 and Tailwind 4.

## Features

- Today dashboard with tasks, project focus, seven-day habit history and quick idea capture.
- Goals, projects, tasks, event calendars and daily check-ins.
- Notes, ideas, content planning and weekly reviews.
- Personal finance with accounts, categories, income, expenses, savings goals and a wishlist.
- Account-owned records, configurable timezone and currency, responsive layouts and light/dark appearance.
- Installable PWA with an offline information screen. Editing personal records requires a connection.

## Project status

This repository contains the application source. Publishing it to GitHub does not deploy the website. Cross-device use requires an HTTPS PHP/PostgreSQL host and signing into the same account on each device.

The AI Assistant is a future-feature placeholder; no AI provider or external personal account is connected. Sample records are available only in local/testing environments. Browser installation, offline behavior and mobile layout still need acceptance testing on real devices.

## Run the prepared Windows workspace

The portable PHP and PostgreSQL runtimes are in the parent workspace's `.runtime` folder. Dependencies, a local database and development sample data have been prepared there.

From this directory, run:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Start-Local.ps1
```

Open <http://127.0.0.1:8000>. Choose **Explore the demo workspace** for the seeded examples, or **Create an account** for a clean personal workspace. Demo access exists only with `APP_ENV=local`. A new account starts with its own finance categories and default account; it does not inherit demo tasks or personal data.

The sample account is `hery@example.test`. Its password is the development-only `DEMO_PASSWORD` value in your local `.env`. Choose a local demo password; do not use the demo account in production.

Closing the browser does not erase records. The local database persists in `.runtime/pgdata`; the application never stores your records in browser localStorage.

## Set up on another machine

Requirements: PHP 8.4.1 or later with ctype, curl, DOM/XML, fileinfo, intl, mbstring, OpenSSL, PDO PostgreSQL, tokenizer and zip; Composer; Node.js 22.12+ or 24 LTS; pnpm; PostgreSQL 16+.

Laravel 13 itself supports PHP 8.3, but the Symfony packages pinned in this project's `composer.lock` require PHP 8.4.1 or later. Check the PHP version used by both the web server and Composer before deployment.

```sh
composer install
pnpm install --frozen-lockfile
cp .env.example .env
# Create a PostgreSQL database and configure DB_* in .env.
php artisan key:generate
php artisan migrate
pnpm build
php artisan serve
```

Optional development samples:

```sh
php artisan db:seed --class=DemoSeeder
```

The seeder refuses to run outside local/testing environments. It does not reset an existing demo user's records. Registration creates clean, separately owned data.

For frontend editing, run `pnpm dev` in a second terminal alongside `php artisan serve`.

## Verification

```sh
php vendor/bin/phpunit
pnpm typecheck
pnpm build
```

The default suite uses isolated in-memory SQLite for quick tests. PostgreSQL is the application database and is also tested using a separate `personal_os_test` database. Never point test execution at an application database containing records you need to keep.

For PostgreSQL verification, set `APP_ENV=testing`, `DB_CONNECTION=pgsql`, `DB_DATABASE=personal_os_test` and the test server's `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, plus `SESSION_DRIVER=array` and `CACHE_STORE=array`, then run:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests
```

## Architecture and data behavior

- Session authentication and Laravel CSRF protection guard every data action. Each record and relation is scoped to the authenticated user.
- `app/Actions/RecordActions.php` is the shared mutation boundary for validation, ownership, exact money conversion, focus changes, ordering, habit toggles, conversion and audit logs. Controllers delegate to this layer.
- Record-specific rules live in `app/Support/RecordRules.php`; typed model classes use ULID identifiers and soft deletes. The database enforces habit/date and review/week uniqueness plus a single current-focus project.
- Home queries derived task progress, project focus, habit completions and financial summaries. Calendars display scheduled events only. To-dos and habits stay in their dedicated sections; daily calendar check-ins remain separate.
- Normal timestamps are UTC. Planning dates, habit days and calendar display use the account timezone, initially Asia/Makassar. Week start is editable.
- Money is stored as integer hundredths and exposed as decimal strings. IDR, JPY and KRW require whole-unit inputs. Financial totals include accounts in the selected currency only; there is no exchange-rate conversion. Savings goals are manual totals.
- Reviews store a computed snapshot when created; editing reflection text preserves that historical snapshot. Changing its date window recomputes the snapshot.
- Notes and scripts are displayed as escaped plain text. No raw user HTML is rendered.
- The AI Assistant screen is intentionally a future-version placeholder. There are no model calls, external integrations, notifications, recurring engines or uploads. PWA installation and a public offline fallback were added after the original V1 scope at the user’s request.

## Production deployment

Use a host that runs PHP and PostgreSQL. Sites/Cloudflare Workers cannot run this Laravel PHP application directly; no public deployment is included in the prepared local workspace.

1. Provision PostgreSQL and an HTTPS PHP web server. Point its document root to this application's `public` directory. For Nginx, route unresolved paths to `index.php` using Laravel's normal configuration.
2. Install production PHP dependencies with `composer install --no-dev --optimize-autoloader`. Build frontend assets with `pnpm install --frozen-lockfile && pnpm build`.
3. Configure secrets on the host: `APP_ENV=production`, `APP_DEBUG=false`, your HTTPS `APP_URL`, a unique `APP_KEY`, database credentials and `SESSION_SECURE_COOKIE=true`. Remove the development `DEMO_PASSWORD`.
4. Ensure `storage` and `bootstrap/cache` are writable by the PHP runtime. Run `php artisan migrate --force`, then `php artisan config:cache` and `php artisan view:cache`.
5. Create a fresh personal account through registration. Configure database backups with the hosting provider. Never copy the development database, `.env` or `.runtime` directory into a public web root.

Registration is available for initial setup; the app has user isolation but does not include an administrative registration-management screen. Password-reset mail is not configured. No Redis, queue worker or scheduled process is required.

## Repository and updates

Keep `.env`, database files/backups, logs, sessions, generated assets, `vendor` and `node_modules` out of Git. `.env.example` contains configuration placeholders, not production credentials. Install dependencies and build assets on the deployment machine or in a separate build process.

The portable `.runtime` directory and prepared PostgreSQL database live outside this repository and are not included. Clone the repository and follow the setup instructions above to prepare another machine.

For updates, commit tested changes and push them to GitHub. Deployment to the hosting account is a separate step unless an automated workflow has been configured. Back up production data before database migrations; preserve the production `.env`, application key and personal records across releases.


## Install as an app (PWA)

Run `pnpm build` and serve the application. The build creates a versioned `public/sw.js` using the public offline resources and frontend build manifest; deploy it together with the other public assets.

- **Desktop Chrome/Edge:** open the website, then choose **Install app** near the bottom of the sidebar or use the browser’s install option. The local `http://127.0.0.1:8000` address supports development installation on this computer.
- **Android:** open the HTTPS website in a supported browser and use **Install app** (under **More** in the mobile navigation), or the browser’s install/add-to-home-screen menu.
- **iPhone/iPad:** use the browser Share menu → **Add to Home Screen**. Keep **Open as Web App** enabled if that option is shown. The in-app Install control explains these steps when a native install prompt is unavailable.
- The app opens in a standalone window with its own icon. Manifest shortcuts open Today and Ideas after the normal authentication checks.

Your phone cannot use the computer’s `127.0.0.1` address. Publish to an HTTPS PHP/PostgreSQL host for use across devices. Do not expose the development server publicly as a substitute for production hosting. The local server must remain running for a locally installed app to access records.

### Offline and update behavior

After a successful first online visit, the service worker stores only the public offline screen and app icons. Full page navigation without a connection shows that screen. If a page is already open, a connection banner appears and saves/navigation are blocked while the browser reports offline. Personal records, authenticated HTML, Inertia responses and APIs are never stored in the service-worker cache. There is no offline editing queue, background sync or push notification setup.

An update notification asks you to save your work and reload. It does not automatically reload a page with an unsaved draft. Cache cleanup only removes older Personal OS public caches. Serve `/sw.js` and `/manifest.webmanifest` with revalidation (`Cache-Control: no-cache`) and the normal JavaScript/manifest MIME types. The worker is served from the web root with scope `/`; never redirect it through an authenticated route. HTTPS is required outside localhost/loopback development.

Service-worker registration is disabled in Vite dev mode to avoid stale development assets. To test the PWA, use `pnpm build` without a running Vite hot server. Icons are included in `public/icons`; the optional `scripts/generate-pwa-icons.py` regenerates them with Python and Pillow.

PWA checks: `node --test tests/frontend/pwa.test.mjs`. These validate the manifest/icon dimensions, public-only cache, navigation fallback, exclusion of API/write requests and update activation. Actual browser installation and offline UI acceptance still need testing: this session’s saved browser permission blocks opening the local preview. No installation on the user’s device has been performed by the agent.

References: [MDN installability requirements](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable), [MDN service workers](https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API/Using_Service_Workers).
