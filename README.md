# NexusSpace

NexusSpace is a PHP and MySQL social website for profiles, posts, comments, friends, and direct messages. Spotify and Steam connections are optional.

## Run locally with Laragon

1. Place the project in Laragon's `www` directory (for example, `C:\laragon\www\nexusspace`). Start Apache and MySQL from Laragon.
2. For a new installation only, import [`database/fresh-install.sql`](database/fresh-install.sql) into MySQL. It creates the `nexusspace` database and its tables. Do not import it over an existing database.
3. Copy `.env.example` to `.env` and set the database host, port, database name, username, and password for your local MySQL setup. Keep `.env` private; it is excluded from Git. A dedicated MySQL account limited to this database is recommended. Use an administrator account when applying SQL migrations.
4. Set `NEXUSSPACE_URL` to the URL you use to open the site, such as `http://localhost/nexusspace`. Configure `SPOTIFY_CLIENT_ID`, `SPOTIFY_REDIRECT_URI`, and `STEAM_WEB_API_KEY` only if you want to use those integrations. Provider callback URLs must match the URLs registered with Spotify and Steam.
5. Open `http://localhost/nexusspace` in a browser. Register an account through the site.

## Existing database upgrades

Do not run `fresh-install.sql` on a database that already contains data. Instead, review the unapplied dated SQL files in [`database/`](database/) and apply each required migration once, in chronological order, using a MySQL administrator account. Back up the database first. The fresh-install schema already contains many changes represented by historical migrations, so do not replay those migrations on a fresh installation.

The local development database may have migrations applied that are not yet applied to another copy. Check the migration files and the project's upgrade notes before deploying changes to another environment.

## Configuration and troubleshooting

- Database credentials and integration secrets belong in `.env`, never in committed source files. `.env.example` is a template, not a working secrets file.
- If the site displays “The application is temporarily unavailable,” confirm that MySQL is running, the database exists, and the values in `.env` let PHP connect to it. Check the PHP and Apache error logs for the server-side detail.
- If Spotify or Steam linking fails, verify the API credentials, public callback URL, and provider-side callback registration.
- Upload size limits are configured in `.user.ini`; PHP or Apache may need to be restarted after changing PHP settings.
- The app uses the `mysqli` PHP extension. Ensure it is enabled in the PHP version selected by Laragon.

## Development data

The SQL files named `dev_seed_*` and `dev_remove_*` create and remove temporary dashboard-pin test friends. Use them only against a local development database, and run the matching removal script when finished.
