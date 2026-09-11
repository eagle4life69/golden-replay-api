# Golden Replay API

A secure, read-only WordPress REST API plugin that provides normalized classic radio episode data for the **Golden Replay** application.

Golden Replay is designed to consume episode information from WordPress without exposing unnecessary WordPress internals or requiring the mobile application to parse rendered post content itself.

## Current Version

**0.1.3**

## Current API Endpoint

```text
/wp-json/golden-replay/v1/episode/{id}
```

Example using WordPress post ID `21571`:

```text
https://www.otrwesterns.com/wp-json/golden-replay/v1/episode/21571
```

The endpoint returns data only for published WordPress posts.

## v0.1.3 Taxonomy Model

Version 0.1.3 establishes the discovery taxonomy that Golden Replay will use across multiple WordPress sites.

### Series

The historical radio series is read from the episode content's `Show:` value. Golden Replay then attempts to match that show name to one of the post's WordPress tags. When a matching series tag is found, its real WordPress term ID and slug are returned. If no reliable match exists, Golden Replay safely falls back to the `Show:` value with a null ID.

Example:

```json
"series": {
  "id": 123,
  "name": "Lux Radio Theatre",
  "slug": "lux_radio_theatre"
}
```

### Primary Genre

The primary browse genre comes from a recognized WordPress category. Current recognized genres include Westerns, Mystery, Drama, Comedy, Crime, Detective, Adventure, Horror, and Science Fiction.

`Western Podcast`, `Western`, and `Westerns` are normalized to the Golden Replay genre `Westerns`.

The existing `genre` field remains as a compatibility alias for `primary_genre` in v0.1.3.

### Episode Genres

`episode_genres` contains the primary category genre plus any additional recognized genre tags assigned to the episode. Duplicate genres are removed.

This supports anthology and cross-genre discovery. For example, a Lux Radio Theatre episode may belong to a Drama catalog while also being discoverable as a Western-themed episode.

Golden Replay can therefore use this browse behavior:

- When a requested genre matches a series' primary catalog genre, the full available series catalog can be shown.
- When a series appears through an additional episode genre, only episodes matching that requested genre should be shown in that browse context.

The actual filtered series/list endpoints will be added after the taxonomy contract has been validated against real data.

### Source

Each episode now includes a `source` object so the app can normalize records from more than one WordPress installation without treating the source site as the genre.

Known sources currently include:

- `otrwesterns` — Old Time Radio Westerns
- `otnetcast` — Old Time Radio Netcast

Unknown installations fall back to their WordPress site name and host-derived source key.

### Publisher Feed

`publisher_feed` remains available as provenance metadata, but it is no longer used as the Golden Replay genre. Categories recognized as genres and season categories are excluded when detecting a publisher/feed grouping.

## Returned Episode Data

The API builds an explicit, whitelisted response containing information such as:

- WordPress post ID
- Stable WordPress URL (`?p=ID`)
- Current pretty URL
- Episode title
- Historical series/program information
- Publisher/feed information
- Primary genre
- Episode discovery genres
- Source WordPress site
- Original historical air date
- WordPress publication and modification dates
- Episode description
- Duration in seconds and display format
- File size in bytes and display format
- Spreaker provider, episode ID, and media URL
- Structured credits
- Availability information

Fields that cannot be reliably determined are returned as `null` or an empty collection rather than fabricated.

## Audio / Spreaker

Golden Replay reads the WordPress `enclosure` post metadata server-side. When a valid Spreaker enclosure is present, the plugin extracts the Spreaker episode ID and media URL.

Spreaker URLs are validated and currently restricted to the `api.spreaker.com` host. The application therefore receives the existing Spreaker-hosted media URL instead of constructing an arbitrary remote URL.

## Security Design

The public API is intentionally read-only and narrowly scoped.

Current protections include:

- Only published WordPress posts are returned.
- Episode IDs are validated as positive integers.
- Responses are constructed from an explicit whitelist of fields.
- Raw WordPress post metadata is not exposed.
- No create, edit, delete, upload, or execution endpoints are provided.
- No database credentials, API keys, access tokens, or other secrets are stored in the plugin.
- The client cannot provide an arbitrary URL for the server to retrieve.
- Spreaker media URLs found in enclosure metadata are validated before being returned.
- Errors use generic messages rather than exposing filesystem paths, SQL, stack traces, or server internals.

The repository is public by design. Security must not depend on hiding the source code.

## Automatic Updates

The plugin includes a native GitHub release updater in `github-updater.php`.

WordPress checks the latest published release from this repository and compares its version with the installed plugin version. Beginning with version 0.1.2, the updater preserves the directory name of the currently installed plugin when preparing GitHub release packages so activation and automatic-update preferences are retained.

A published GitHub Release is required for WordPress to discover a new version. The release tag should correspond to the plugin version, such as `v0.1.3`.

## Development Workflow

The `main` branch represents release-ready code and should remain protected.

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

Version 0.1.3 adds the normalized series/genre/source taxonomy needed before Golden Replay begins implementing browse endpoints such as genres, series, years, and filtered episode lists.

The existing single-episode endpoint and Spreaker enclosure behavior remain intact.

## Publisher

**Rhynes Media LLC**

## License

GPL-2.0-or-later
