# Changelog

## Unreleased

- Hosted servers follow their plan: VENTASYNC_EXT_* switches decide which extensions run, VENTASYNC_MAX_* values cap products, users, stores, API apps and orders a month, and retention days clear old logs, activity and waybills. Self-hosted installs set none of these and are not limited.
- Settings, Error log no longer fills with notices that Laravel and Symfony silence on purpose; real warnings and errors still show.

## 0.30.0 (2026-10-07)

- First numbered release, opening the closed beta.
- Master Catalog with variations, pictures, watermarks, product groups and a listing per store.
- Orders with their profit, fulfilment with a packing check, returns, reviews, payouts and fees.
- Stock and price pushes, scheduled syncs and a stock history for every change.
- Users, user groups and permissions; a REST API and a sign-in API for mobile apps.
- Extensions switch on and off under Settings, Extensions, with a Refresh button for the server's cache.
