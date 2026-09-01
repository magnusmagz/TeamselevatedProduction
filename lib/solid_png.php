<?php
/**
 * Build a solid-colour PNG in pure PHP.
 *
 * WHY BY HAND
 * The Heroku dyno has neither GD nor Imagick (checked 2026-08-28), so there is
 * no image library to call. A solid rectangle needs none: a PNG is a signature
 * plus three chunks, the pixel data is one repeated triplet, and both DEFLATE
 * (gzcompress) and CRC-32 (crc32) are PHP built-ins. Uniform data compresses to
 * a few hundred bytes whatever the dimensions.
 *
 * WHY THIS EXISTS AT ALL
 * Canva's autofill fills text and images and CANNOT set colours or fonts. That
 * is what forces a brand template per club — the club's colour has to be baked
 * into the artwork. Handing Canva a colour as an IMAGE is the way around it: a
 * neutral template with an image frame shaped like a bar, a panel or a
 * background takes the club's colour from a block generated here, so one
 * template serves every club.
 *
 * Fonts remain shared: there is no equivalent trick for typography.
 */

/** Bytes of a PNG chunk, including its length prefix and CRC. */
function te_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/**
 * Normalise a colour to [r, g, b], or null if it is not a colour we can read.
 *
 * Accepts "#RRGGBB", "RRGGBB", "#RGB". Returns null rather than guessing —
 * a wrong colour on a club's artwork is worse than no colour block, and the
 * caller can then refuse the graphic instead of shipping something off-brand.
 */
function te_parse_hex_color(?string $hex): ?array
{
    if ($hex === null) {
        return null;
    }
    $hex = ltrim(trim($hex), '#');

    if (preg_match('/^[0-9a-f]{3}$/i', $hex)) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
        return null;
    }

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * A solid-colour PNG, or null when the colour cannot be read.
 *
 * Default size is generous because a Canva image frame crops to fill: a block
 * smaller than the frame would be upscaled, and while a flat colour survives
 * that perfectly, some pipelines refuse very small images. Uniform pixels mean
 * the file stays tiny regardless — a 1200x1200 block is under a kilobyte.
 */
function te_solid_color_png(?string $hex, int $width = 1200, int $height = 1200): ?string
{
    $rgb = te_parse_hex_color($hex);
    if ($rgb === null) {
        return null;
    }

    $width  = max(1, min(4096, $width));
    $height = max(1, min(4096, $height));

    // Colour type 2 (truecolour), 8 bits per channel. Each scanline is prefixed
    // with filter byte 0 (None) — filtering buys nothing on uniform data.
    $pixel    = chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]);
    $scanline = "\x00" . str_repeat($pixel, $width);
    $raw      = str_repeat($scanline, $height);

    $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

    return "\x89PNG\r\n\x1a\n"
        . te_png_chunk('IHDR', $ihdr)
        . te_png_chunk('IDAT', gzcompress($raw, 9))
        . te_png_chunk('IEND', '');
}
