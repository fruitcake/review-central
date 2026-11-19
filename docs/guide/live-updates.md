# Live updates

Without anything set, an open page asks again by itself: the review's page every three seconds while the review runs. With a broadcaster the pages are told instead: the overview and the list of reviews when a review starts or ends, and a review's page when its log grows. Set `BROADCAST_CONNECTION` in `.env`:

- `pusher`: Pusher, or a server of your own that speaks its protocol (Reverb, Soketi). Set `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET` and `PUSHER_APP_CLUSTER`, and `PUSHER_HOST`, `PUSHER_PORT` and `PUSHER_SCHEME` for a server of your own.
- `mercure`: a Mercure hub. Set `MERCURE_URL` (where this server publishes), `MERCURE_PUBLIC_URL` (where browsers subscribe) and `MERCURE_JWT_SECRET` (the hub's key for publishers and subscribers, at least 32 bytes). The hub tells who may listen from a cookie this site sets, so it has to be on this site's domain or a subdomain of it.
- `null`, the default: nothing is broadcast.

Run `php artisan config:clear` (or `config:cache`) after changing it. Nothing has to be built: the browser's side is in `public/js/laravel-echo.js`, and is made with what `.env` says.

A broadcast only says that something changed, never what. The page then reads it from this server, so no code and no review text reaches the broadcaster, and a page that missed one is only late: it still asks every ten seconds while a review runs.

Broadcasts are sent as they happen, not from the queue: the worker that would send them may be the one running the review. A broadcaster that does not answer within a few seconds is skipped.
