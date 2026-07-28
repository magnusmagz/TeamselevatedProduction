-- 049_club_logo_png.sql
-- Email-safe club logo cache.
--
-- club_profile.logo_url holds whatever format the club uploaded (PNG, SVG, AVIF).
-- Email clients only reliably render PNG/JPEG/GIF, and cannot render SVG or AVIF,
-- so we cache an email-ready PNG (rasterized + downscaled) plus its display
-- dimensions. Served by api/club-logo.php and used by CalendarInviteService.
--
-- logo_png : base64 of an email-ready PNG (no data: prefix)
-- logo_w   : intrinsic width  of that PNG in px (stored at 2x display size)
-- logo_h   : intrinsic height of that PNG in px (stored at 2x display size)

ALTER TABLE club_profile ADD COLUMN IF NOT EXISTS logo_png TEXT;
ALTER TABLE club_profile ADD COLUMN IF NOT EXISTS logo_w SMALLINT;
ALTER TABLE club_profile ADD COLUMN IF NOT EXISTS logo_h SMALLINT;
