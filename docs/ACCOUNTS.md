# Accounts, names and owners

Every account, key and name the project uses, who holds it, and where its secret lives. Secrets are
never written here, in git or in a chat: only where they are kept.

Roles:
- **Owner**: Hussam, who runs the company and this project.
- **Server**: the code on api.nastastopp.se; no person signs in with its accounts.
- **Device**: a driver's phone or tablet, enrolled with a code.

| What | Name | Role | Where its secret lives |
|---|---|---|---|
| Company email | `admin@nastastopp.se` (Hostinger → Emails) | Owner | Owner's password manager |
| Google Cloud project | `nastastopp-prod` | Owner (signs in with the company email) | Google account |
| Google key for ways and travel times | `nastastopp-routes-server`: Routes API only, restricted to the server's IP | Server | `nastastopp-config.php` → `google_routes_key` |
| Google key for the tablet's map | `nastastopp-maps-js-tablet`: Maps JavaScript API only | Device (tablets) | `nastastopp-config.php` → `google_maps_js_key` |
| mapmap.ai key | `nastastopp-prod-server` | Server | `nastastopp-config.php` → `mapmap_key` |
| MySQL database | `u840798620_nastastopp` (type `nastastopp`; Hostinger adds the prefix) | Server | — |
| MySQL user | `u840798620_nastastopp_api` (type `nastastopp_api`) | Server only; the owner never signs in with it | `nastastopp-config.php` → `db_pass` (made by hPanel's generator, 24+ characters) |
| Admin page password | admin.nastastopp.se | Owner only | Owner's password manager; its hash in `nastastopp-config.php` |
| Device codes | 8 letters and digits, made on the admin page | One device each, once, 7 days | Only shown once on the admin page |
| Device labels | `<role>-<car>`: `tablet-40274`, `phone-40274` | Device | — (never a passenger's name) |
| Git deploy | Hostinger → Advanced → Git: `HussamEl/nastastopp-web`, branch `main`, directory `public_html/nastastopp`, auto-deployment on: every push to `main` goes live | Server | — |
| Hostinger GitHub App | Installed on `HussamEl`, "Only select repositories": `Eldebosh` and `nastastopp-web` (never the app's repository) | Owner | GitHub → Settings → Applications |
| Subdomains | `api.nastastopp.se` → `public_html/nastastopp/api`, `admin.nastastopp.se` → `public_html/nastastopp/admin`; `nastastopp.se` → `public_html/nastastopp/site` | — | — |
| Secrets file | `nastastopp-config.php`, one folder above `public_html` | Server | Written by the owner in File Manager |
| Android signing key | `nastastopp-release.jks` | Owner | Owner's private backup; `~/.nastastopp-signing/` on the build machine |
