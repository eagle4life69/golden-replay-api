# Golden Replay API

A secure, read-only WordPress REST API plugin that provides normalized classic radio episode and catalog data for the **Golden Replay** application.

## Current Version

**0.1.22**

## v0.1.22 Series Catalog Regression Repair

Version 0.1.22 repairs the series/catalog regression introduced in the v0.1.20 package while retaining the intended Golden Replay API browse model.

- Restores historical series identification from the episode content's `Show:` value instead of choosing an arbitrary WordPress category as the series.
- Restores normalized canonical series keys and known series aliases.
- Restores the complete genre -> series -> year/season -> episode browse flow.
- Restores primary-versus-secondary genre behavior used by `/series`, `/seasons`, `/episodes`, and `/latest`.
- Preserves partial Original Air Dates introduced in v0.1.19.
- Restores the persistent catalog/index cache and invalidation behavior.
- Corrects both the WordPress plugin header and `GRAPI_VERSION` constant to `0.1.22`.

This repair specifically addresses cases such as **The Six Shooter**, where an episode can be correctly identified as a Western but fail to appear as the expected series because v0.1.20 derived the series from unrelated category hierarchy information.

See `RELEASE-NOTES-v0.1.22.md` for release details.

## Current API Endpoints

```text
/wp-json/golden-replay/v1/episode/{id}
/wp-json/golden-replay/v1/genres
/wp-json/golden-replay/v1/latest?genre={genre-slug}
/wp-json/golden-replay/v1/series?genre={genre-slug}
/wp-json/golden-replay/v1/seasons?genre={genre-slug}&series={series-key}
/wp-json/golden-replay/v1/episodes?genre={genre-slug}&series={series-key}
/wp-json/golden-replay/v1/episodes?genre={genre-slug}&series={series-key}&season={season-slug}
```

All endpoints expose information derived only from published WordPress posts.

## Taxonomy Model

### Series

The historical radio series is read from the episode content's `Show:` value and normalized into a canonical series key. Matching WordPress tags are used as source provenance and, for primary-series browsing, to discover the complete published series set.

Known aliases include:

- `Lone Ranger` and `The Lone Ranger` -> `The Lone Ranger`
- `Wild Bill Hickok` and `Adventures of Wild Bill Hickok` -> `Adventures of Wild Bill Hickok`
- `Grand Old Opry` and `Grand Ole Opry` -> `Grand Ole Opry`

### Show Category and Seasons

A show's WordPress category may contain direct child season categories. Year-coded season categories are used directly. For ordinal seasons, Golden Replay derives browse years from episode `original_air_date` values.

### Primary Genre

The primary browse genre comes from a recognized WordPress category. Current recognized genres include Westerns, Mystery, Drama, Comedy, Crime, Detective, Adventure, Horror, and Science Fiction.

`Western Podcast`, `Western`, and `Westerns` normalize to the Golden Replay genre `Westerns`.

### Episode Genres

`episode_genres` contains the primary category genre plus recognized genre tags assigned to an episode. Duplicate canonical genres are removed.

## Original Air Dates

Golden Replay preserves incomplete historical dates rather than inventing missing components:

- `1949` -> `1949-00-00`
- `May 1949` -> `1949-05-00`
- `May 12, 1949` -> `1949-05-12`

Missing or unrecognized historical dates remain unknown.

## Catalog and Episode Browsing

The API supports the server-side browse flow used by the SwiftUI client:

`Genres -> Series -> Year/Season -> Episodes -> Episode detail/audio`

Episode results are ordered by `original_air_date` before pagination. Programs with year-coded season categories browse by those categories; ordinal-season programs can use calendar years derived from episode air dates.

The `/latest` endpoint uses WordPress publication date rather than historical air date so featured content follows the site's current release schedule.

## Admin Enclosure Inspector

The plugin includes an administrator-only diagnostic tool under **Settings -> Golden Replay API**. The Enclosure Inspector accepts a WordPress post ID or episode URL and displays stored `enclosure` metadata for troubleshooting audio variants.

It requires `manage_options`, uses WordPress nonce protection, does not add a public REST route, and does not expose alternate enclosure URLs through the public Golden Replay API.

## Audio / Spreaker

Golden Replay reads WordPress `enclosure` post metadata server-side. Valid Spreaker URLs are restricted to `api.spreaker.com`. Duration and file-size values remain nullable when the source integration has not populated them.

## Security Design

The public API is intentionally read-only and narrowly scoped. Only published posts are returned, counted, or included in catalog discovery. Inputs are validated and sanitized, responses are explicitly constructed, raw WordPress post meta is not exposed, and no create/edit/delete/upload/execute endpoints or secrets are provided.

## Catalog Cache

Genre, series, and episode catalog data is stored in persistent WordPress options. Post/category/tag changes advance the catalog generation and schedule a background rebuild through WP-Cron. A 24-hour maximum cache age provides a safety refresh and a rebuild lock prevents overlapping rebuild jobs.

## Automatic Updates

The plugin includes the native GitHub release updater in `github-updater.php`. A published GitHub Release is required for WordPress to discover a new version. Release tags should correspond to plugin versions, for example `v0.1.22`.

## Release History

- **0.1.22** — Repairs the v0.1.20 series/catalog regression and restores `Show:`-based series identification and the full browse/catalog implementation.
- **0.1.20** — Release package that introduced the series/catalog regression repaired by 0.1.22.
- **0.1.19** — Preserves partial Original Air Dates and replaces the incomplete 0.1.18 package.
- **0.1.18** — Incomplete release package; should not be installed.
- **0.1.17** — Corrected plugin version metadata for the Enclosure Inspector release.
- **0.1.15** — Added `/latest` for newest published episode by genre.
- **0.1.14** — Refreshed catalog cache namespace.
- **0.1.13** — Improved series counts and derived-year browsing.
- **0.1.12** — Distinguished ordinal seasons from two-digit year codes.
- **0.1.11** — Added episode description fallback parsing.
- **0.1.10** — Added season/year browsing.
- **0.1.9** — Added historical episode ordering.
- **0.1.8** — Added filtered episode catalog endpoint.
- **0.1.7** — Added persistent catalog caching and background rebuilds.
- **0.1.6** — Added series normalization and aliases.

## Development Workflow

```text
Create a temporary feature/fix branch
        ↓
Make and test changes
        ↓
Update README.md for the change
        ↓
Open or update the Pull Request into main
        ↓
Review and merge
        ↓
Publish a GitHub Release
        ↓
WordPress detects the newer release
```

**README requirement:** Every functional plugin change must include the corresponding README update in the same branch/PR so documentation stays synchronized with the code.

## Publisher

**Rhynes Media LLC**

## License

GPL-2.0-or-later
