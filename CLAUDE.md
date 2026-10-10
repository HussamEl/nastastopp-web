# nastastopp-web: working rules

The server and site of Nästa Stopp, on Hostinger Business Web Hosting (PHP 8, MySQL, Git deploy):
- `api/`: api.nastastopp.se. An enrolled tablet gets its map key here (`GET /v1/config`) and asks for its ways and travel times; the server asks Google (Routes API) or mapmap.ai with the company's own keys. A device leaves with `POST /v1/leave` (the app's Disconnect).
- `admin/`: the owner's page. It creates codes for new devices, lists devices and their daily requests, and stops a device.
- `site/`: nastastopp.se, the public page.
- `src/`: the code; `tests/run.php` is its tests (SQLite in memory, a fake Google/mapmap): `php tests/run.php` must print "All passed".

## The user
Hussam, the owner, writes Arabic: reply in Arabic, concisely. The Android app is in `HussamEl/S20Ultra` (its own CLAUDE.md has the app's hard rules).
The laptop session (Claude Code on Hussam's computer, with his browser) carries out setup steps in hPanel and GitHub. Claude coordinates with it directly on `HussamEl/S20Ultra` PR #1 (`CLAUDE REQUEST <topic>`), without asking Hussam to relay, and tells him what both did. Signing in, passwords, keys, payments and terms stay Hussam's alone.

## Professional standard (decided by the owner)
The project is built to be sold one day to a large company: everything must pass its engineers' review.
- **Names are chosen, never improvised.** Every account, database, user, key, folder and label has a proposed name and an owner in `docs/ACCOUNTS.md`. When a step asks the owner to create something, give the exact name to type and say whose it is (the owner's, the server's, or a device's). The owner is not a programmer: never leave him to guess.
- **Instructions for the owner** are numbered steps, right-aligned, with every English word (a menu, a field, a value) on its own line or in its own box, exactly as the screen shows it; a long guide goes on a page (an Artifact), not in the chat.
- **Separation:** secrets only in the server's config file or the owner's password manager; accounts in the company's name (`admin@nastastopp.se`), not a personal one; the server's accounts are never used by a person.
- **Quality:** tests for every behaviour and a CI check on every push (`.github/workflows/tests.yml`); small commits with clear messages; docs that say what is and why (README for the owner in Arabic, CLAUDE.md and code comments in English).
- **Licences and data:** every outside source has its licence and attribution in the repo; personal data is kept to the minimum the service needs (GDPR), never logged.

## Current decision (by the owner)
No Google Cloud project, billing or key for the company for now, and no key on the server: the tablet asks mapmap.ai by default with the driver's own mapmap key, and Google only with his own key on his own devices. The server's `/v1/config` answers `503 no-key` until keys are decided; moving routing and keys to the server is a later decision. The code for it stays ready and tested.

## Hard rules
- **No secret in git, code, logs or chat.** Keys, the database password and the admin password's hash live only in `nastastopp-config.php`, one folder above `public_html`, written by Hussam on the server (`config.example.php` shows its shape). Never ask him to paste one here.
- **No key ever reaches a device**, except the Maps JavaScript key, which a tablet's map page needs; that key is restricted in Google Cloud to the Maps JavaScript API and to the website `https://map.nastastopp.se/*` (the origin the app loads its map page under; the name is never served).
- **Only tablets get the map key and the ways** (a phone gets 403 `role`). Any device may enroll and leave.
- **The server keeps nothing a request carries**: no position, point, address or name, no body, nothing logged. It keeps only devices (label, role, token hash, active, when made and last seen), unused codes (hash) and counts of requests per device per day per service. A Google body may carry a stop's address when the stop has no point: it is passed on, never kept.
- Tokens and codes are stored only as SHA-256. A code works once, for 7 days. A stopped device (the owner's Stop, or its own `/v1/leave`) gets 403 `stopped`; a token no device holds gets 401 `not-enrolled`.
- Everything is HTTPS (each folder's `.htaccess`); the repository root denies everything else.
- TLS is always verified when the server asks Google or mapmap; curl follows no redirect and speaks only HTTPS.
- Only these are passed on, each checked before the request counts:
  - to Google, a field mask that is exactly one of the app's two (`Api::ROUTE_FIELDS`, `Api::MATRIX_FIELDS`), and a body of only the fields the app sends: driving, each place a point or an address, at most 10 stops between, at most 100 answers in a matrix (so the requests stay in Google's basic tier);
  - to mapmap, a body of points only, never a point in a URL a device asks, so none reaches Hostinger's access log (`POST /v1/mapmap/route` with `points` checked pair by pair, and its `overview`, `geometries`, `steps` options, which the server then puts in mapmap's own route URL; a matrix of `{lat, lon}` only).
- **The masks and bodies couple the app and the server.** They are copied byte for byte from the app's `core/nav/RoutesApi.kt` (and `tests/run.php` copies them again); the app's `WayRequestsTest` copies the server's. A change in the app's requests needs this server changed and deployed first, or every such request gets 400.
- **The server's own refusals carry `X-Nasta-Error`**, a fixed word from `Api::ERRORS` (never input), and the daily limit also `Retry-After` (the seconds to the next UTC midnight). Google's and mapmap's answers are passed on as they came, without it, so the app never takes their 401 or 429 for the server's.
- A request counts only after every check (token, role, size, mask, body, the server's key) has passed, and a count never passes the device's daily limit for that service (`Store::count`, one atomic update).
