from pathlib import Path

path = Path('golden-replay-api.php')
text = path.read_text()

replacements = [
    (' * Version: 0.1.13', ' * Version: 0.1.14'),
    ("define( 'GRAPI_VERSION', '0.1.13' );", "define( 'GRAPI_VERSION', '0.1.14' );"),
    ("private static function series_cache_key( $slug ) { return 'grapi_series_catalog_v3_' . md5( sanitize_title( $slug ) ); }", "private static function series_cache_key( $slug ) { return 'grapi_series_catalog_v4_' . md5( sanitize_title( $slug ) ); }"),
    ("private static function episode_index_cache_key( $slug ) { return 'grapi_episode_index_v2_' . md5( sanitize_title( $slug ) ); }", "private static function episode_index_cache_key( $slug ) { return 'grapi_episode_index_v3_' . md5( sanitize_title( $slug ) ); }"),
]

for old, new in replacements:
    if old not in text:
        raise SystemExit(f'Missing expected text: {old}')
    text = text.replace(old, new, 1)

path.write_text(text)
