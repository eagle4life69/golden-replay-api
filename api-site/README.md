# Golden Replay catalog API

This `api-site/` directory is separate from the WordPress plugin in the repository root. Deploy only the contents of this directory to the document root of `api.goldenreplay.app` (`/goldenreplay/api` on IONOS). The shared PDO configuration remains outside the document root at `/goldenreplay/private/config/database.php`. It may return a PDO, set `$pdo`, or return an array with `dsn`, `username`, and `password`. Never place credentials in this repository.

The read-only endpoints are `/v1/health`, `/v1/genres`, `/v1/series?genre=...`, `/v1/seasons?genre=...&series=...`, `/v1/episodes?genre=...&series=...&season=gr-year-YYYY&page=1&per_page=25`, `/v1/episode/{id}`, and `/v1/latest?genre=...`. They provide the iOS app's existing JSON contract, with canonical database IDs in `post_id`. Only active episodes with an active free version and playable audio source appear. The latest endpoint sorts by publisher publication time.

`POST /v1/legacy/resolve` accepts `{"post_ids":[123]}` (up to 200 WordPress IDs) and returns unique OTRWesterns-to-canonical matches, including canonical show and season keys. Unknown and ambiguous IDs are omitted so client data can remain intact. No privileged data or premium audio is exposed.

Before switching the app, deploy this API and check health, genre, series, year, detail, and legacy resolution against real catalog rows. Confirm the host routes `/v1/*` to this directory's `index.php` and that `private/config/database.php` matches one of the supported formats.
