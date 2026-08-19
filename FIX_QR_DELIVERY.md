# QR delivery fix

Changes applied:
- Telegram `bot()` now sends `CURLFile` payloads as multipart/form-data instead of converting them with `http_build_query()`.
- `sendPhoto()` now detects local generated QR image paths and uploads them directly to Telegram.
- Order-delivery QR, manual QR buttons, and payment callback QR delivery now use local file upload instead of temporary public URLs.
- Existing fallback text/link sending remains in place if Telegram rejects photo delivery.

Reason:
Telegram sometimes failed to fetch temporary QR URLs before the bot deleted the generated PNG, so the order/subscription was created but QR was not delivered. Direct upload removes that race condition.
