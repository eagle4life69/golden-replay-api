# Golden Replay API

A secure, read-only WordPress REST API plugin that provides normalized classic radio episode and catalog data for the **Golden Replay** application.

## Current Version

**0.1.7**

## Current API Endpoints

```text
/wp-json/golden-replay/v1/episode/{id}
/wp-json/golden-replay/v1/genres
/wp-json/golden-replay/v1/series?genre={genre-slug}
```

All endpoints expose information derived only from published WordPress posts.

## v0.1.7 Catalog Performance

Version 0.1.7 changes the genre and series catalog cache from short-lived five-minute transients to persistent prebuilt WordPress options.

Normal API requests return the existing catalog immediately. Catalog records store a build timestamp and generation number. Post saves/deletes and category/tag changes advance the catalog generation and schedule a background rebuild through WP-Cron rather than deleting the existing catalog and forcing the next API caller to rebuild it.

A 24-hour maximum cache age provides a safety refresh. Stale catalogs continue to be served while a rebuild is scheduled, providing stale-while-revalidate behavior.

The expensive catalog builder itself is unchanged in this release; the performance improvement comes from moving that work away from ordinary cached API requests.

On a brand-new installation or after the v0.1.7 cache-key change, the first request for a catalog that has never been built can still be slow because it must create the initial cache once. Subsequent requests use the persistent cache.

A rebuild lock prevents overlapping scheduled catalog rebuild jobs.

## v0.1.6 Series Normalization

Version 0.1.6 adds a canonical series identity layer so historical inconsistencies in WordPress data do not become duplicate programs in Golden Replay.

Series names are cleaned before matching. Unicode/nonbreaking whitespace is normalized, repeated whitespace is collapsed, and a stray leading `Show:` prefix is removed.

Known aliases currently include:

- `Lone Ranger` and `The Lone Ranger` -> `The Lone Ranger`
- `Wild Bill Hickok` and `Adventures of Wild Bill Hickok` -> `Adventures of Wild Bill Hickok`
- `Grand Old Opry` and `Grand Ole Opry` -> `Grand Ole Opry`

Each normalized series has a stable application-facing `key` independent of source-local WordPress term IDs. Source taxonomy provenance remains available through `id`, `source_slug`, and `source_terms`.

## v0.1.5 Series Catalog

`/wp-json/golden-replay/v1/series?genre=westerns` provides genre-aware series discovery. It finds published episodes through primary genre categories and secondary genre tags, groups them by series, and identifies each result as a `primary` or `episode` genre match.

Each series includes matching and primary episode counts. A primary match takes precedence when a series has both primary and secondary matches.

## v0.1.4 Genre Catalog

`/wp-json/golden-replay/v1/genres` returns recognized genres with published episodes. `episode_count` includes primary-category and secondary-tag discovery matches; `primary_episode_count` includes primary-category matches only.

## Taxonomy Model

### Series

The historical radio series is read from the episode content's `Show:` value. Golden Replay cleans and canonicalizes that value, then attempts to match it to an assigned WordPress tag for source provenance.

WordPress term IDs are source-local and are not global Golden Replay identifiers. The canonical `key` is the application-facing identity used to merge known aliases and, eventually, records from multiple sources.

### Primary Genre

The primary browse genre comes from a recognized WordPress category. Current recognized genres include Westerns, Mystery, Drama, Comedy, Crime, Detective, Adventure, Horror, and Science Fiction.

`Western Podcast`, `Western`, and `Westerns` normalize to the Golden Replay genre `Westerns`.

The episode endpoint's existing `genre` field remains a compatibility alias for `primary_genre`.

### Episode Genres

`episode_genres` contains the primary category genre plus any additional recognized genre tags assigned to an episode. Duplicate canonical genres are removed.

### Source

Each catalog response identifies its WordPress source. Known sources currently include `otrwesterns` (Old Time Radio Westerns) and `otnetcast` (Old Time Radio Netcast).

### Publisher Feed

`publisher_feed` remains provenance metadata and is not used as the Golden Replay genre.

## Audio / Spreaker

Golden Replay reads WordPress `enclosure` post metadata server-side. Valid Spreaker URLs are restricted to `api.spreaker.com`. Duration and file-size values remain nullable because the Spreaker WordPress integration may not populate them until metadata has been refreshed.

## Security Design

The public API is intentionally read-only and narrowly scoped. Only published posts are returned, counted, or included in catalog discovery. Episode IDs are validated, series genre input is allowlisted, responses are explicitly constructed, raw WordPress post meta is not exposed, and no create/edit/delete/upload/execute endpoints or secrets are provided.

## Automatic Updates

The plugin includes the existing native GitHub release updater in `github-updater.php`. Beginning with version 0.1.2, the updater preserves the installed plugin directory so activation and automatic-update preferences remain stable across GitHub release updates.

A published GitHub Release is required for WordPress to discover a new version. Release tags should correspond to plugin versions, such as `v0.1.7`.

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

Version 0.1.7 improves catalog response performance by persistently caching generated catalogs and rebuilding stale data asynchronously. The next catalog step is the filtered episode-list endpoint that preserves selected genre and canonical-series context.

## Publisher

**Rhynes Media LLC**

## License

GPL-2.0-or-later
