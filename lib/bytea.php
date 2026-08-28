<?php
/**
 * Writing binary into a Postgres BYTEA column from this codebase.
 *
 * ⚠️ THE OBVIOUS WAY SILENTLY WRITES NOTHING. Verified against production
 * 2026-08-28:
 *
 *     $st = $pdo->prepare("UPDATE t SET image_data = ? WHERE id = ?");
 *     $st->execute([$pngBytes, $id]);   // returns FALSE. Does not throw.
 *
 *   execute returned: false  rowCount=0
 *
 * `config/database.php` sets PDO::ATTR_EMULATE_PREPARES => true (needed for
 * Neon's connection pooling), so the statement is assembled client-side into one
 * string. A PNG contains NUL bytes, that string is handed to libpq as a
 * C string, and it is cut at the first NUL. PDO reports the failure by RETURN
 * VALUE only — ERRMODE_EXCEPTION does not raise here — so a caller that ignores
 * the return, as every caller in this codebase does, sees success.
 *
 * That is exactly how the first Canva graphic reported "Round trip works" while
 * storing a row with image_data NULL and status still 'rendering'.
 *
 * The fix is to send hex, which contains no NUL and no quoting hazard, and let
 * Postgres decode it:
 *
 *     $sql = "UPDATE t SET image_data = " . TE_BYTEA_PARAM . " WHERE id = ?";
 *     $st->execute([te_bytea_hex($bytes), $id]);
 *
 * Then CHECK. te_bytea_stored_length() re-reads the column, because the whole
 * failure mode here is a write that lies about succeeding.
 *
 * Note club_profile.logo_png is TEXT holding base64, not BYTEA — which is why
 * this codebase went years without meeting this. club_media_assets.image_data
 * is the first genuine BYTEA column.
 */

/** SQL fragment to use in place of a bare `?` when writing a BYTEA column. */
const TE_BYTEA_PARAM = "decode(?, 'hex')";

/**
 * Binary → the hex string TE_BYTEA_PARAM expects.
 *
 * Doubles the payload in the statement (a 380 KB PNG becomes 760 KB of hex).
 * That is the cost of emulated prepares and it is worth paying: the alternative
 * is a write that fails without saying so.
 */
function te_bytea_hex(string $binary): string
{
    return bin2hex($binary);
}

/**
 * Actual stored byte length, straight from Postgres. NULL if the row or column
 * is empty.
 *
 * Use it to verify a write landed. Never trust strlen() of what you meant to
 * store — that number is true regardless of whether the database received it.
 */
function te_bytea_stored_length(PDO $pdo, string $table, string $column, string $idColumn, $id): ?int
{
    // Identifiers cannot be bound, and these are caller-supplied, so they are
    // restricted to plain identifiers rather than quoted-and-hoped-for.
    foreach ([$table, $column, $idColumn] as $ident) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $ident)) {
            throw new InvalidArgumentException("Refusing suspicious identifier: {$ident}");
        }
    }

    $stmt = $pdo->prepare("SELECT octet_length({$column}) FROM {$table} WHERE {$idColumn} = ?");
    $stmt->execute([$id]);
    $len = $stmt->fetchColumn();

    return $len === false || $len === null ? null : (int) $len;
}
