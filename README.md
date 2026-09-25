# AniRead

AniRead is a PHP 8.1+/MySQL anime catalog and authorized-source player designed for ordinary shared hosting. It imports metadata from the public Jikan API; it does **not** provide or invent video streams.

## Install
1. Create a MySQL/MariaDB database.
2. Import `install.sql` in phpMyAdmin.
3. Copy `config/config.example.php` to `config/config.php` and enter database credentials and the public site URL.
4. Upload the files to PHP 8.1+ hosting with PDO MySQL enabled.
5. Register an account, then promote it once with `UPDATE users SET is_admin=1 WHERE email='your@email';` in phpMyAdmin.
6. Run `cron/sync_anime.php` from hosting cron, or add a small admin action that invokes the same script through the browser.
7. Add episodes and actual authorized MP4/HLS/iframe sources in the database/admin tools before playback.

## Important operating rules
- Metadata synchronization and video availability are separate systems.
- Only active sources in `video_sources` are shown. If none exists the watch page says “Streaming source unavailable for this episode.”
- Do not scrape, mirror, hotlink, download, bypass DRM, or add unauthorized copyrighted streams.
- Use HTTPS in production and keep `config/config.php` outside public web access if the host supports that.

The starter provides the catalog, details, search, genres, authentication, watchlist, history, watch page, schema, sitemap, responsive UI, and repeat-safe Jikan synchronization foundation. Admin CRUD screens can be extended using the prepared schema and existing PDO helpers without Node, Composer, Docker, Redis, or SSH.
