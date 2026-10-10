# nastastopp-web: working rules

The server and site of Nästa Stopp, on Hostinger Business Web Hosting (PHP 8, MySQL, Git deploy):
- `api/`: api.nastastopp.se. Enrolled devices ask for their ways and travel times here; the server asks Google (Routes API) or mapmap.ai with the company's own keys.
- `admin/`: the owner's page. It creates codes for new devices, lists devices and their daily requests, and stops a device.
- `site/`: nastastopp.se, the public page.
- `src/`: the code; `tests/run.php` is its tests (SQLite in memory, a fake Google/mapmap): `php tests/run.php` must print "All passed".

## The user
Hussam, the owner, writes Arabic: reply in Arabic, concisely. The Android app is in `HussamEl/S20Ultra` (its own CLAUDE.md has the app's hard rules).

## Hard rules
- **No secret in git, code, logs or chat.** Keys, the database password and the admin password's hash live only in `nastastopp-config.php`, one folder above `public_html`, written by Hussam on the server (`config.example.php` shows its shape). Never ask him to paste one here.
- **No key ever reaches a device**, except the Maps JavaScript key, which a tablet's map page needs; that key is restricted in Google Cloud to the Maps JavaScript API.
- **The server keeps nothing a request carries**: no position, point, address or name, no body, nothing logged. It keeps only devices (label, role, token hash), unused codes (hash) and counts of requests per device per day per service.
- Tokens and codes are stored only as SHA-256. A code works once, for 7 days.
- Everything is HTTPS (each folder's `.htaccess`); the repository root denies everything else.
- TLS is always verified when the server asks Google or mapmap.
- Only these are passed on: a Google field mask of letters, digits, `_ . , *`; a mapmap path of points only, and its `overview`, `geometries`, `steps` options.
