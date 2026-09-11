# Golden Replay API

A secure, read-only WordPress REST API plugin that provides normalized classic radio episode data for the **Golden Replay** application.

Golden Replay is designed to consume episode information from WordPress without exposing unnecessary WordPress internals or requiring the mobile application to parse rendered post content itself.

## Current Version

**0.1.4**

## Current API Endpoints

```text
/wp-json/golden-replay/v1/episode/{id}
/wp-json/golden-replay/v1/genres
```

The endpoints return information derived only from published WordPress posts.

## v0.1.4 Genre Catalog

Version 0.1.4 adds the first Golden Replay browse/catalog endpoint:

```text
/wp-json/golden-replay/v1/genres
```

The endpoint returns only recognized genres that currently contain at least one published episode on the WordPress site.

Each genre includes:

- `name` — canonical Golden Replay genre name.
- `slug` — canonical cross-site genre slug.
- `episode_count` — published episodes discoverable through either the primary genre category or an additional recognized genre tag.
- `primary_episode_count` — published episodes whose recognized genre category matches the genre.

For example, an OTNetcast Escape episode categorized as Mystery and tagged Drama counts toward both Mystery and Drama in `episode_count`, but only toward Mystery in `primary_episode_count`.

Counts are built with WordPress taxonomy queries rather than parsing every post body. Results are cached for five minutes to keep the public catalog endpoint lightweight.

The response also includes the current WordPress source so Golden Replay can combine catalogs from multiple installations.

Example response shape:

```json
{
  "source": {
    "key": "otnetcast",
    "name": "Old Time Radio Netcast",
    "site_url": "https://otnetcast.com/"
  },
  "genres": [
    {
      "name": "Mystery",
      "slug": "mystery",
      "episode_count": 100,
      "primary_episode_count": 100
    },
    {
      "name": "Drama",
      "slug": "drama",
      "episode_count": 60,
      "primary_episode_count": 0
    }
  ]
}
```

## Taxonomy Model

### Series

The historical radio series is read from the episode content's `Show:` value. Golden Replay then attempts to match that show name to one of the post's WordPress tags. When a matching series tag is found, its real WordPress term ID and slug are returned. If no reliable match exists, Golden Replay safely falls back to the `Show:` value with a null ID.

### Primary Genre

The primary browse genre comes from a recognized WordPress category. Current recognized genres include Westerns, Mystery, Drama, Comedy, Crime, Detective, Adventure, Horror, and Science Fiction.

`Western Podcast`, `Western`, and `Westerns` are normalized to the Golden Replay genre `Westerns`.

The existing `genre` field remains as a compatibility alias for `primary_genre`.

### Episode Genres

`episode_genres` contains the primary category genre plus any additional recognized genre tags assigned to the episode. Duplicate genres are removed.

This supports anthology and cross-genre discovery. When a requested genre matches a series' primary catalog genre, the full available series catalog can be shown. When a series appears through an additional episode genre, only episodes matching that requested genre should be shown in that browse context.

### Source

Each response identifies its WordPress source so the app can normalize records from more than one WordPress installation without treating the source site as the genre.

Known sources currently include:

- `otrwesterns` — Old Time Radio Westerns
- `otnetcast` — Old Time Radio Netcast

Unknown installations fall back to their WordPress site name and host-derived source key.

### Publisher Feed

`publisher_feed` remains available as provenance metadata, but it is not used as the Golden Replay genre. Categories recognized as genres and season categories are excluded when detecting a publisher/feed grouping.

## Returned Episode Data

The episode endpoint builds an explicit, whitelisted response containing information such as WordPress post ID, stable URL, episode title, series, publisher/feed information, genres, source, original air date, publication dates, description, duration, file size, Spreaker media information, structured credits, and availability.

Fields that cannot be reliably determined are returned as `null` or an empty collection rather than fabricated.

## Audio / Spreaker

Golden Replay reads the WordPress `enclosure` post metadata server-side. When a valid Spreaker enclosure is present, the plugin extracts the Spreaker episode ID and media URL.

Spreaker URLs are validated and currently restricted to the `api.spreaker.com` host. The application therefore receives the existing Spreaker-hosted media URL instead of constructing an arbitrary remote URL.

## Security Design

The public API is intentionally read-only and narrowly scoped.

Current protections include:

- Only published WordPress posts are returned or counted.
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

A published GitHub Release is required for WordPress to discover a new version. The release tag should correspond to the plugin version, such as `v0.1.4`.

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

Version 0.1.4 adds the first browse endpoint, `/genres`. The next catalog work can build on this contract with series and filtered episode-list endpoints.

## Publisher

**Rhynes Media LLC**

## License

GPL-2.0-or-later
