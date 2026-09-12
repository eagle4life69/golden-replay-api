# Golden Replay API

A secure, read-only WordPress REST API plugin that provides normalized classic radio episode and catalog data for the **Golden Replay** application.

## Current Version

**0.1.8**

## Current API Endpoints

```text
/wp-json/golden-replay/v1/episode/{id}
/wp-json/golden-replay/v1/genres
/wp-json/golden-replay/v1/series?genre={genre-slug}
/wp-json/golden-replay/v1/episodes?genre={genre-slug}&series={series-key}
```

All endpoints expose information derived only from published WordPress posts.

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

The historical radio series is read from the episode content's `Show:` value. Golden Replay cleans and canonicalizes that value, then attempts to match it to an assigned WordPress tag for source provenance.

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

A published GitHub Release is required for WordPress to discover a new version. Release tags should correspond to plugin versions, such as `v0.1.8`.

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

Version 0.1.8 completes the initial read-only browse API needed for `Genres -> Series -> Episodes -> Episode detail/audio`. The next milestone is wiring these endpoints into the first SwiftUI Golden Replay prototype.

## Publisher

**Rhynes Media LLC**

## License

GPL-2.0-or-later
