# Golden Replay API

A secure, read-only WordPress REST API plugin that provides normalized classic radio episode and catalog data for the **Golden Replay** application.

## Current Version

**0.1.15**

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

## v0.1.15 Latest Published Episode

Version 0.1.15 adds the `/latest` endpoint used by the Golden Replay Home screen to retrieve the newest published episode for a selected genre.

Example:

```text
/wp-json/golden-replay/v1/latest?genre=westerns
```

The endpoint selects the most recent episode using the WordPress publication date, not the episode's historical `original_air_date`. This keeps Home-screen featured content aligned with what was most recently released on the site while preserving historical air-date ordering in the existing `/episodes` browse endpoint.

The response includes the selected genre plus the complete normalized episode payload, including series information, description, original air date, published date, duration, audio information, credits, and availability.

For a primary-genre series, the same series-selection rules used by the browse API are preserved so published episodes belonging to that series may still qualify even when an individual post is missing the primary genre taxonomy. Secondary genre matches remain limited to episodes that actually match the selected genre.

## v0.1.14 Catalog Cache Namespace Refresh

Version 0.1.14 bumps the persistent catalog cache namespace so corrected series counts and derived-year behavior introduced in v0.1.13 become visible immediately instead of waiting for previously stored catalog data to age out.

No public endpoint contract changed in v0.1.14.

## v0.1.13 Series Counts and Derived Year Browsing

Version 0.1.13 fixes several issues exposed by programs such as **Lux Radio Theatre** that use true ordinal season categories while also having episodes identified through series tags and historical `Show:` values.

- Primary-series episode counts now include tag-discovered episodes used by the `all_series` selection mode.
- Derived year browsing is built from the complete selected series episode set instead of creating separate year rows for each ordinal season category.
- Episodes that belong to the selected series but are not assigned to a child season category are still included in the appropriate derived year.
- Derived year slugs use sanitizer-safe values such as `gr-year-1939` and `gr-year-unknown`.
- `/seasons` counts and `/episodes?season=...` now use the same selected episode set, so a displayed year count should match the episodes returned when that year is opened.
- Direct taxonomy-season behavior remains unchanged for true year-coded season categories.

## v0.1.12 Ordinal Seasons and Year Codes

Version 0.1.12 distinguishes true season numbers from abbreviated year codes.

Two-digit season values from **20 through 80** are treated as twentieth-century year codes. For example, `Season 53` is presented as `1953` and `Season 80` as `1980`.

Season values outside that range are treated as ordinal season numbers. Their browse years are derived from each episode's `original_air_date`, allowing a single ordinal season to span more than one calendar year. `Season 00` remains the convention for `Unknown`.

## v0.1.11 Episode Description Parsing

Version 0.1.11 adds description fallback parsing for the existing WordPress authoring format.

If descriptive text appears before `Original Air Date:`, that leading text is returned as the episode `description`. If `Original Air Date:` is the first meaningful line, `description` remains null. An explicit `Description:` label is still supported but is not required.

## v0.1.10 Season / Year Browsing

Version 0.1.10 adds season/year discovery so long-running programs can be browsed without loading thousands of episodes into the client.

`/seasons` requires the canonical genre slug and canonical series key returned by the catalog endpoints. Golden Replay discovers a series' WordPress show category and reads its direct child categories that follow the existing `Season ##` naming convention. The prefix is not hard-coded, so categories such as `TCK Season 53` and `LR Season 53` are handled by their parent/child relationship rather than by the show-specific prefix.

The existing `/episodes` endpoint accepts an optional `season` parameter containing the season slug returned by `/seasons`. When supplied, only episodes for that browse selection are returned. Air-date ordering and pagination are then applied to that filtered set. When `season` is omitted, the series behavior remains unchanged for backward compatibility.

## v0.1.9 Historical Episode Ordering

Version 0.1.9 orders episode results by `original_air_date` across the complete selected set before pagination. Episodes with known air dates are ordered chronologically; episodes without a known air date are placed after dated episodes and use title ordering as the fallback.

## v0.1.8 Filtered Episode Catalog

Version 0.1.8 adds the episode-list endpoint used for the Golden Replay browse flow: `Genre -> Series -> Episodes`.

`/episodes` requires the canonical genre slug and canonical series key returned by the catalog endpoints. Results are paginated with a default of 25 episodes per page and a maximum of 100. `page`, `per_page`, and `order=asc|desc` are supported.

The selected genre context is preserved when opening a series. When the selected genre is the series' primary genre, the endpoint returns all published episodes belonging to that series. When the series appears only because individual episodes carry the selected genre as a secondary match, only those matching episodes are returned.

The episode index is generated alongside the existing series catalog and stored in the same persistent cache model introduced in v0.1.7. Post/category/tag changes continue to invalidate the catalog generation and schedule a background rebuild.

## v0.1.7 Catalog Performance

Version 0.1.7 changes the genre and series catalog cache from short-lived five-minute transients to persistent prebuilt WordPress options.

Normal API requests return the existing catalog immediately. Catalog records store a build timestamp and generation number. Post saves/deletes and category/tag changes advance the catalog generation and schedule a background rebuild through WP-Cron rather than deleting the existing catalog and forcing the next API caller to rebuild it.

A 24-hour maximum cache age provides a safety refresh. Stale catalogs continue to be served while a rebuild is scheduled, providing stale-while-revalidate behavior. A rebuild lock prevents overlapping scheduled catalog rebuild jobs.

## v0.1.6 Series Normalization

Series names are cleaned before matching. Unicode/nonbreaking whitespace is normalized, repeated whitespace is collapsed, and a stray leading `Show:` prefix is removed.

Known aliases currently include:

- `Lone Ranger` and `The Lone Ranger` -> `The Lone Ranger`
- `Wild Bill Hickok` and `Adventures of Wild Bill Hickok` -> `Adventures of Wild Bill Hickok`
- `Grand Old Opry` and `Grand Ole Opry` -> `Grand Ole Opry`

Each normalized series has a stable application-facing `key` independent of source-local WordPress term IDs.

## Taxonomy Model

### Series

The historical radio series is read from the episode content's `Show:` value and normalized into a canonical series key. Golden Replay also uses matching WordPress tags as source provenance and, for primary-series browsing, to discover the complete published series set.

### Show Category and Seasons

A show's WordPress category may contain direct child season categories, for example:

```text
Western Podcast
└── Cisco Kid
    ├── TCK Season 00
    ├── TCK Season 52
    ├── TCK Season 53
    └── TCK Season 54
```

For year-coded season structures, the category hierarchy remains the source of truth. For ordinal season structures, Golden Replay derives browse years from the selected series episodes' `original_air_date` values so episodes are grouped by calendar year even when a traditional season spans multiple years.

### Primary Genre

The primary browse genre comes from a recognized WordPress category. Current recognized genres include Westerns, Mystery, Drama, Comedy, Crime, Detective, Adventure, Horror, and Science Fiction.

`Western Podcast`, `Western`, and `Westerns` normalize to the Golden Replay genre `Westerns`.

### Episode Genres

`episode_genres` contains the primary category genre plus any additional recognized genre tags assigned to an episode. Duplicate canonical genres are removed.

### Source and Publisher Feed

Each catalog response identifies its WordPress source. `publisher_feed` remains provenance metadata and is not used as the Golden Replay genre.

## Audio / Spreaker

Golden Replay reads WordPress `enclosure` post metadata server-side. Valid Spreaker URLs are restricted to `api.spreaker.com`. Duration and file-size values remain nullable because the Spreaker WordPress integration may not populate them until metadata has been refreshed.

## Security Design

The public API is intentionally read-only and narrowly scoped. Only published posts are returned, counted, or included in catalog discovery. Inputs are validated and sanitized, responses are explicitly constructed, raw WordPress post meta is not exposed, and no create/edit/delete/upload/execute endpoints or secrets are provided.

## Automatic Updates

The plugin includes the existing native GitHub release updater in `github-updater.php`. The updater preserves the installed plugin directory so activation and automatic-update preferences remain stable across GitHub release updates.

A published GitHub Release is required for WordPress to discover a new version. Release tags should correspond to plugin versions, such as `v0.1.15`.

## Development Workflow

```text
Create a temporary feature/fix branch
        ↓
Make and test changes
        ↓
Open a Pull Request into main
        ↓
Review and merge
        ↓
Publish a GitHub Release
        ↓
WordPress detects the newer release
```

## Current Development Status

Version 0.1.15 provides the server-side browse flow used by the SwiftUI client: `Genres -> Series -> Year -> Episodes -> Episode detail/audio`, plus a dedicated `Latest Published Episode` lookup for Home-screen featured content. Historical browsing continues to use `original_air_date`, while `/latest` uses the WordPress publication date so featured content follows the site's release schedule.

Programs with valid year-coded season categories continue to browse by those categories, while ordinal-season programs can use calendar years derived from episode air dates. Programs without usable season/year browsing continue to support direct series-to-episodes requests.

## Publisher

**Rhynes Media LLC**

## License

GPL-2.0-or-later
