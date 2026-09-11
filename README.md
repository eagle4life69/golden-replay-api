# Golden Replay API

A secure, read-only WordPress REST API plugin that provides normalized classic radio episode and catalog data for the **Golden Replay** application.

## Current Version

**0.1.5**

## Current API Endpoints

```text
/wp-json/golden-replay/v1/episode/{id}
/wp-json/golden-replay/v1/genres
/wp-json/golden-replay/v1/series?genre={genre-slug}
```

All endpoints expose information derived only from published WordPress posts.

## v0.1.5 Series Catalog

Version 0.1.5 adds genre-aware series discovery:

```text
/wp-json/golden-replay/v1/series?genre=westerns
```

The `genre` parameter is required and must be a recognized canonical Golden Replay genre slug.

The endpoint finds published episodes discoverable through either the requested primary genre category or an additional recognized genre tag, groups those episodes by their historical `Show:`/series identity, and returns an alphabetized series catalog.

Each series includes:

- `id` — source WordPress series tag term ID when a reliable tag match exists.
- `name` — historical series/program name.
- `slug` — canonicalized series slug for application use.
- `source_slug` — source WordPress series tag slug when available.
- `match_type` — `primary` when the requested genre is a primary category for episodes in the series, otherwise `episode`.
- `matching_episode_count` — number of published episodes from this source that match the requested genre context.
- `primary_episode_count` — number of those matching episodes whose primary category is the requested genre.

A primary match takes precedence if a series has both primary and secondary matches.

This supports the Golden Replay browsing rule: a series can appear because it belongs to the requested catalog genre, or because individual episodes are additionally tagged for discovery in that genre. The later filtered episode-list endpoint will use the same genre context to decide whether to expose the full available series catalog or only genre-matching episodes.

Series results are cached for five minutes. The endpoint validates genre input against the explicit Golden Replay genre allowlist and does not accept arbitrary taxonomy or query parameters.

Example response shape:

```json
{
  "source": {
    "key": "otnetcast",
    "name": "Old Time Radio Netcast",
    "site_url": "https://otnetcast.com/"
  },
  "genre": {
    "name": "Drama",
    "slug": "drama"
  },
  "series": [
    {
      "id": 26,
      "name": "Escape",
      "slug": "escape",
      "source_slug": "escape",
      "match_type": "episode",
      "matching_episode_count": 100,
      "primary_episode_count": 0
    }
  ]
}
```

## v0.1.4 Genre Catalog

```text
/wp-json/golden-replay/v1/genres
```

The genre endpoint returns recognized genres that contain at least one published episode on the current WordPress site.

Each genre includes a canonical `name` and `slug`, `episode_count` for episodes discoverable through either the primary category or an additional genre tag, and `primary_episode_count` for episodes whose recognized category is that genre.

Genre results are built with WordPress taxonomy queries and cached for five minutes.

## Taxonomy Model

### Series

The historical radio series is read from the episode content's `Show:` value. Golden Replay attempts to match that show name to an assigned WordPress tag. When a matching series tag is found, its real source WordPress term ID and slug are returned. If no reliable match exists, Golden Replay safely falls back to the `Show:` value with a null ID.

WordPress term IDs are source-local. Golden Replay should use normalized series identity rather than treating a term ID from one WordPress site as a global identifier.

### Primary Genre

The primary browse genre comes from a recognized WordPress category. Current recognized genres include Westerns, Mystery, Drama, Comedy, Crime, Detective, Adventure, Horror, and Science Fiction.

`Western Podcast`, `Western`, and `Westerns` normalize to the Golden Replay genre `Westerns`.

The episode endpoint's existing `genre` field remains a compatibility alias for `primary_genre`.

### Episode Genres

`episode_genres` contains the primary category genre plus any additional recognized genre tags assigned to an episode. Duplicate canonical genres are removed.

This supports anthology and cross-genre discovery without fabricating classifications that are not present in the source data.

### Source

Each catalog response identifies its WordPress source so Golden Replay can normalize records from multiple installations.

Known sources currently include:

- `otrwesterns` — Old Time Radio Westerns
- `otnetcast` — Old Time Radio Netcast

Unknown installations fall back to their WordPress site name and a host-derived source key.

### Publisher Feed

`publisher_feed` remains available on episode records as provenance metadata. It is not used as the Golden Replay genre.

## Audio / Spreaker

Golden Replay reads WordPress `enclosure` post metadata server-side. When a valid Spreaker enclosure is present, the plugin extracts the Spreaker episode ID and existing media URL.

Spreaker URLs are validated and restricted to the `api.spreaker.com` host. Duration and file-size values remain nullable because the Spreaker WordPress integration may not populate those enclosure values until its metadata has been refreshed.

## Security Design

The public API is intentionally read-only and narrowly scoped.

Current protections include:

- Only published WordPress posts are returned, counted, or included in catalog discovery.
- Episode IDs are validated as positive integers.
- Series genre input is restricted to the explicit Golden Replay genre allowlist.
- Responses are constructed from explicit fields rather than exposing raw WordPress records or post meta.
- No create, edit, delete, upload, or execution endpoints are provided.
- No database credentials, API keys, access tokens, or other secrets are stored in the plugin.
- The client cannot provide an arbitrary URL for the server to retrieve.
- Spreaker media URLs found in enclosure metadata are validated before being returned.
- Errors use generic messages rather than exposing filesystem paths, SQL, stack traces, or server internals.
- Catalog results are cached to reduce repeated public-query load.

The repository is public by design. Security does not depend on hiding the source code.

## Automatic Updates

The plugin includes the existing native GitHub release updater in `github-updater.php`. Beginning with version 0.1.2, the updater preserves the installed plugin directory so activation and automatic-update preferences remain stable across GitHub release updates.

A published GitHub Release is required for WordPress to discover a new version. Release tags should correspond to plugin versions, such as `v0.1.5`.

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

Version 0.1.5 adds genre-aware series discovery. The next catalog step is a filtered episode-list endpoint that preserves the selected genre context when a listener enters a series.

## Publisher

**Rhynes Media LLC**

## License

GPL-2.0-or-later
