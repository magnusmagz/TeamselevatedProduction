<?php
/**
 * Keep a parent's `guardians` row in step with their `users` row.
 *
 * A parent's login lives in `users`. What their club sees — the Crew page, every
 * send, every export — lives in `guardians`. `/parent/settings` wrote `users`
 * only, so a parent could change their email or name there and the club would
 * still hold the old one indefinitely.
 *
 * ⚠️ MATCHING IS ON EMAIL **AND** NAME, NEVER EMAIL ALONE.
 * Households share an address on purpose: 6 addresses in production are held by 2
 * guardians each (John & Jane Jones on thejones@…, Morgan & Zach Powell on
 * morganbmiles@…, and four more). Matching on email alone would rewrite BOTH
 * people's contact record when one of them changed theirs — silently handing the
 * club a wrong address for a parent who never touched their settings.
 *
 * This is the same email-as-identity weakness the rest of the codebase works
 * around, and it cannot be solved here: the real fix is the `user_guardians` link
 * table on the backlog. Until then, name narrows it enough to be safe, and
 * anything ambiguous is reported rather than guessed at.
 */

/**
 * Guardian rows that plausibly belong to this user.
 *
 * @param array $user  users row as it was BEFORE the update — the old email is
 *                     what the guardian row still carries
 * @return array[] guardian rows {id, first_name, last_name, email}
 */
function te_find_guardian_rows_for_user(PDO $pdo, array $user): array
{
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT id, first_name, last_name, email
         FROM guardians
         WHERE LOWER(email) = ?
           AND LOWER(TRIM(first_name)) = ?
           AND LOWER(TRIM(last_name)) = ?"
    );
    $stmt->execute([
        $email,
        strtolower(trim((string) ($user['first_name'] ?? ''))),
        strtolower(trim((string) ($user['last_name'] ?? ''))),
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Apply a parent's profile change to their guardian row(s).
 *
 * Only the keys present in $changes are written, so a partial save cannot blank a
 * field it never sent — the same rule `legacy/guardian-gateway.php` follows.
 *
 * Returns what happened rather than a bare bool, because the caller has to audit
 * it: a change that matched no guardian row is not an error, but it does mean the
 * club still holds the old details and somebody may need to know.
 *
 * @param array $before  users row before the update (old email + name)
 * @param array $changes subset of {email, first_name, last_name, phone}
 * @return array{matched:int, updated:int, guardian_ids:int[], shared_email:bool}
 */
function te_sync_guardian_contact(PDO $pdo, array $before, array $changes): array
{
    $rows = te_find_guardian_rows_for_user($pdo, $before);

    $result = [
        'matched' => count($rows),
        'updated' => 0,
        'guardian_ids' => [],
        'shared_email' => false,
    ];

    if (!$rows) {
        return $result;
    }

    // Flag when the OLD address is shared with someone else. The name filter has
    // already excluded them from $rows, so nothing wrong is being written — but
    // it is the situation where a wrong write would do the most damage, so the
    // audit record should say it was in play.
    $shared = $pdo->prepare("SELECT COUNT(*) FROM guardians WHERE LOWER(email) = ?");
    $shared->execute([strtolower(trim((string) ($before['email'] ?? '')))]);
    $result['shared_email'] = ((int) $shared->fetchColumn()) > count($rows);

    $map = [
        'email' => 'email',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'phone' => 'mobile_phone',   // guardians stores it as mobile_phone
    ];

    $sets = [];
    $vals = [];
    foreach ($map as $key => $column) {
        if (array_key_exists($key, $changes)) {
            $sets[] = "$column = ?";
            $vals[] = $changes[$key];
        }
    }

    if (!$sets) {
        return $result;
    }

    $stmt = $pdo->prepare('UPDATE guardians SET ' . implode(', ', $sets) . ' WHERE id = ?');
    foreach ($rows as $row) {
        $stmt->execute(array_merge($vals, [$row['id']]));
        $result['updated'] += $stmt->rowCount();
        $result['guardian_ids'][] = (int) $row['id'];
    }

    return $result;
}
