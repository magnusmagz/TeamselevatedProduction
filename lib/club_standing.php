<?php
/**
 * Club-level standing: membership vs staff vs admin.
 *
 * `AuthMiddleware::canAccessClub()` answers "does this user hold ANY role scoped
 * to this club". A `parent` row satisfies it. That is correct for "may I see
 * something about my own club" and wrong for every handler whose answer is
 * club-wide staff data.
 *
 * It was wrong in exactly that way on `handleClubParents`: a parent POSTing their
 * own club_id received every guardian in the club — name, email, mobile phone,
 * portal status and their children's names. Verified against production with a
 * real parent token, HTTP 200. Club 32 exposed 196 guardians to 13 parent
 * accounts; club 51, 148 to 2.
 *
 * The same substitution mistake as `AthleteScope::userCanAccessAthlete` vs
 * `staffCanManageAthlete` — a read predicate used where a staff predicate was
 * meant. Named separately here so the choice has to be deliberate.
 */

/** super_admin, or club_admin of this club. Club-wide staff data. */
function te_is_club_admin($auth, int $clubId): bool
{
    if ($auth->isSuperAdmin()) {
        return true;
    }
    return $auth->hasRole('club_admin', $clubId, 'club');
}

/**
 * Club admin OR coach. For per-athlete or per-guardian staff actions where a
 * coach has a legitimate role — sending one invite, reading one portal status.
 *
 * Deliberately NOT used for club-wide lists: a coach is scoped to their teams,
 * so handing them the whole club roster is the same category of over-sharing,
 * just with a smaller audience.
 */
function te_is_club_staff($auth, int $clubId): bool
{
    if (te_is_club_admin($auth, $clubId)) {
        return true;
    }
    return $auth->hasRole('coach', $clubId, 'club');
}
