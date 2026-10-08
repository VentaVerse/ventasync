# Changelog

## Unreleased

- Five wrong passwords in a row lock an account for 15 minutes, on the web and in the mobile app; an admin can unlock it sooner from Settings, Users. Locks and unlocks are in the activity log.
- On a hosted (cloud) server, store addresses must be public https addresses; private and local addresses are refused, and requests do not follow redirects. Self-hosted installs are unchanged.
- Changing a password signs that person out everywhere else: other browsers, the mobile app and Claude or ChatGPT sign-ins. The browser where it was changed stays signed in.
- Editing an order keeps stock true: adding a product deducts it, changing a quantity moves only the difference, removing a product gives its stock back, and changing the status in the same save gives back exactly what was deducted. Works for orders from any channel edited in VentaSync.
- The AI Assistant extension is now the MCP Server (id mcp); /mcp, sign-ins, connections and permissions carry over.
- Hosted servers follow their plan: VENTASYNC_EXT_* switches decide which extensions run, VENTASYNC_MAX_* values cap products, users, stores, API apps and orders a month, and retention days clear old logs, activity and waybills. Self-hosted installs set none of these and are not limited.
- Settings, Error log no longer fills with notices that Laravel and Symfony silence on purpose; real warnings and errors still show.

## 0.30.0 (2026-10-07)

- First numbered release, opening the closed beta.
- Master Catalog with variations, pictures, watermarks, product groups and a listing per store.
- Orders with their profit, fulfilment with a packing check, returns, reviews, payouts and fees.
- Stock and price pushes, scheduled syncs and a stock history for every change.
- Users, user groups and permissions; a REST API and a sign-in API for mobile apps.
- Extensions switch on and off under Settings, Extensions, with a Refresh button for the server's cache.
