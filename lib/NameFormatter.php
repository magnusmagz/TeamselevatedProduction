<?php
/**
 * Person-name formatting for merge fields and household combining.
 *
 * Ported from the CRM Killer reference (backend/utils/titleCase.js +
 * services/recipientNameResolver.js). Two jobs:
 *   1. titleCaseName() — clean up imported names (ALL CAPS / all lowercase)
 *      without mangling intentional mixed case: McDonald, O'Brien, MacGregor.
 *   2. combineFirstNames()/combineFullNames() — collapse a shared-email
 *      household into one greeting: "John & Jane" / "John & Jane Doe".
 */
class NameFormatter
{
    /** Smart title case for a person name. Empty in -> '' out. */
    public static function titleCaseName(?string $name): string
    {
        if ($name === null) return '';
        $trimmed = trim($name);
        if ($trimmed === '') return '';

        // Process each hyphen/space-separated part independently, keeping separators.
        $parts = preg_split('/(\s+|-)/', $trimmed, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($parts as $part) {
            if ($part === '' ) continue;
            if (preg_match('/^[\s-]+$/', $part)) { $out .= $part; continue; }
            // Intentional mixed case already (e.g. "McDonald") — leave it. Only
            // normalize when it's entirely upper or entirely lower.
            if ($part !== mb_strtoupper($part) && $part !== mb_strtolower($part)) {
                $out .= $part;
                continue;
            }
            $out .= self::titleCasePart($part);
        }
        return $out;
    }

    private static function titleCasePart(string $part): string
    {
        // O'Brien, D'Angelo
        if (preg_match("/^[a-zA-Z]'/", $part) && strlen($part) > 2) {
            return strtoupper($part[0]) . "'" . strtoupper($part[2]) . strtolower(substr($part, 3));
        }
        // McDonald, McGregor
        if (preg_match('/^mc[a-zA-Z]/i', $part) && strlen($part) > 2) {
            return 'Mc' . strtoupper($part[2]) . strtolower(substr($part, 3));
        }
        // MacGregor (length > 4 avoids false positives like "Mack")
        if (preg_match('/^mac[a-zA-Z]/i', $part) && strlen($part) > 4) {
            return 'Mac' . strtoupper($part[3]) . strtolower(substr($part, 4));
        }
        return mb_strtoupper(mb_substr($part, 0, 1)) . mb_strtolower(mb_substr($part, 1));
    }

    /** First token of a full name ("John Michael Doe" -> "John"). */
    public static function firstNameOf(string $full): string
    {
        $full = trim($full);
        if ($full === '') return '';
        $parts = preg_split('/\s+/', $full);
        return $parts[0] ?? '';
    }

    /** Split a full name into ['first','last'] on the first space. */
    public static function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/', ' ', $full));
        if ($full === '') return ['first' => '', 'last' => ''];
        $sp = strpos($full, ' ');
        if ($sp === false) return ['first' => $full, 'last' => ''];
        return ['first' => substr($full, 0, $sp), 'last' => substr($full, $sp + 1)];
    }

    /**
     * Combine first names into a greeting, title-cased and de-duplicated:
     * 1 -> "John", 2 -> "John & Jane", 3+ -> "Bob, Bill & Betty".
     */
    public static function combineFirstNames(array $firstNames): string
    {
        $seen = [];
        $clean = [];
        foreach ($firstNames as $n) {
            $n = self::titleCaseName((string) $n);
            if ($n === '') continue;
            $key = mb_strtolower($n);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $clean[] = $n;
        }
        $c = count($clean);
        if ($c === 0) return '';
        if ($c === 1) return $clean[0];
        if ($c === 2) return $clean[0] . ' & ' . $clean[1];
        return implode(', ', array_slice($clean, 0, -1)) . ' & ' . end($clean);
    }

    /**
     * Combine full names with smart last-name handling:
     * same last name -> "John & Jane Doe"; different -> "John Doe & Jane Smith".
     * $people is a list of ['first'=>, 'last'=>].
     */
    public static function combineFullNames(array $people): string
    {
        $seen = [];
        $clean = [];
        foreach ($people as $p) {
            $first = self::titleCaseName((string) ($p['first'] ?? ''));
            $last  = self::titleCaseName((string) ($p['last'] ?? ''));
            if ($first === '') continue;
            $key = mb_strtolower(trim("$first $last"));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $clean[] = ['first' => $first, 'last' => $last];
        }
        $c = count($clean);
        if ($c === 0) return '';
        if ($c === 1) return trim($clean[0]['first'] . ' ' . $clean[0]['last']);

        $lasts = array_map(fn($p) => mb_strtolower($p['last']), $clean);
        $allSameLast = $lasts[0] !== '' && count(array_unique($lasts)) === 1;
        if ($allSameLast) {
            $firsts = self::combineFirstNames(array_column($clean, 'first'));
            return trim($firsts . ' ' . $clean[0]['last']);
        }
        $full = array_map(fn($p) => trim($p['first'] . ' ' . $p['last']), $clean);
        if (count($full) === 2) return $full[0] . ' & ' . $full[1];
        return implode(', ', array_slice($full, 0, -1)) . ' & ' . end($full);
    }
}
