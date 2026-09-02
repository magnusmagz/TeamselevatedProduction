<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * registration/registrations-api.php had no authentication of any kind.
 *
 * Its POST is a public sign-up and is correctly open. Everything else was open
 * with it:
 *
 *   GET    ?program_id=N  -> every family in that program, form_data included
 *   PUT    ?id=N          -> set status to anything, reviewed_by to anyone
 *   DELETE ?id=N          -> DELETE FROM registrations WHERE id = ?
 *
 * The DELETE is a hard delete, so one integer removed a family's registration
 * permanently, anonymously. The PUT reached `status = 'approved'`, which mints an
 * athlete_payment, an invoice, and a parent-portal invite email.
 *
 * Same lesson as AthleteController and legacy/guardian-gateway.php: nothing
 * upstream authenticates a gateway file, so the auth is the file's own to write,
 * and the absence of a UI is not an access control.
 *
 * Parse-based on purpose. The handlers are inline `case` blocks in one switch
 * reading php://input, so there is no function to call from a test — and, as with
 * the guardian-link tests, the failure mode being guarded is never a broken
 * predicate, it is a predicate that does not get called.
 */
class RegistrationWriteScopeTest extends TestCase
{
    private const API = __DIR__ . '/../../registration/registrations-api.php';

    /**
     * Strip comments before any "must NOT contain" assertion.
     *
     * The comments in this file name the wrong thing in order to explain why it
     * was wrong ("reviewed_by used to come from the body"), so a scanner reading
     * prose flags the documentation as the defect.
     */
    private function code(string $src): string
    {
        if (strpos($src, '<?php') === false) {
            $src = "<?php\n" . $src;
        }

        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** The four method branches of the request switch, keyed by method. */
    private function cases(): array
    {
        $src = $this->code(file_get_contents(self::API));

        if (!preg_match_all("/case\s+'(GET|POST|PUT|DELETE)'\s*:/", $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('No method switch found in registrations-api.php');
        }

        $out = [];
        foreach ($m[1] as $i => $hit) {
            $start = $m[0][$i][1];
            $end = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($src);
            $out[$hit[0]] = substr($src, $start, $end - $start);
        }

        return $out;
    }

    public function testEveryMethodBranchIsPresent(): void
    {
        $cases = $this->cases();

        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $this->assertArrayHasKey($method, $cases, "No {$method} branch found");
        }
    }

    /**
     * requireAuth() exits 401 itself, so the call is the whole check.
     */
    public function testReviewAndDeleteAuthenticate(): void
    {
        $cases = $this->cases();

        foreach (['PUT', 'DELETE'] as $method) {
            $this->assertStringContainsString(
                'AuthMiddleware::requireAuth()',
                $cases[$method],
                "The {$method} branch of registrations-api.php does not authenticate. "
                . 'Nothing upstream does it either, so the handler is reachable with no token.'
            );
        }
    }

    /**
     * te_is_club_staff, not canAccessClub: a `parent` role scoped to the club
     * satisfies the latter, and approving registrations or hard-deleting them is
     * not something a family may do to their club's programs.
     */
    public function testReviewAndDeleteRequireClubStaff(): void
    {
        $cases = $this->cases();

        foreach (['PUT', 'DELETE'] as $method) {
            $this->assertStringContainsString(
                'te_is_club_staff(',
                $cases[$method],
                "The {$method} branch must gate on te_is_club_staff for the club that "
                . 'owns the registration.'
            );
            $this->assertStringNotContainsString(
                'canAccessClub',
                $cases[$method],
                "The {$method} branch gates on club MEMBERSHIP. A parent holds a role "
                . 'scoped to the club and would pass it.'
            );
        }
    }

    /**
     * The club comes from the registration's program, never from the caller.
     * A body-supplied club_id is a claim, and gating on a claim gates nothing.
     */
    public function testTheClubIsResolvedFromTheRegistrationNotTheRequest(): void
    {
        $cases = $this->cases();

        foreach (['PUT', 'DELETE'] as $method) {
            $this->assertDoesNotMatchRegularExpression(
                '/\$data\s*\[\s*[\'"]club_id/',
                $cases[$method],
                "The {$method} branch takes club_id from the request body."
            );
        }

        $this->assertStringContainsString(
            'te_registration_club_id(',
            $cases['DELETE'],
            'The DELETE branch must resolve the registration club from the database.'
        );
    }

    /**
     * reviewed_by is the record of who approved a registration. Taken from the
     * body it records whoever the caller nominated.
     */
    public function testTheReviewerIsTakenFromTheTokenNotTheBody(): void
    {
        $put = $this->cases()['PUT'];

        $this->assertStringContainsString(
            '$auth->getUserId()',
            $put,
            'reviewed_by must be the authenticated user.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$data\s*\[\s*[\'"]reviewed_by/',
            $put,
            'reviewed_by is read from the request body, so the audit trail records '
            . 'whichever user id the caller supplied.'
        );
    }

    /**
     * registrations.status has no CHECK constraint in Neon, so the whitelist is
     * the only thing between the review screen and an arbitrary string.
     */
    public function testStatusIsValidatedAgainstAWhitelist(): void
    {
        $put = $this->cases()['PUT'];

        $this->assertStringContainsString(
            'TE_REGISTRATION_REVIEW_STATUSES',
            $put,
            'The PUT branch writes status unvalidated.'
        );
        $this->assertStringContainsString('in_array(', $put);

        $src = $this->code(file_get_contents(self::API));
        foreach (['pending', 'approved', 'rejected'] as $status) {
            $this->assertMatchesRegularExpression(
                '/TE_REGISTRATION_REVIEW_STATUSES\s*=\s*\[[^\]]*[\'"]' . $status . '[\'"]/s',
                $src,
                "'{$status}' is a live registrations.status value and must stay writable."
            );
        }
    }

    /**
     * A registration row carries the family's whole form submission. The program
     * listing is club-wide family data (staff), while the athlete listing is what
     * the parent portal reads about its own child — so that one takes the READ
     * predicate, whose guardian branch is the point.
     */
    public function testRegistrationReadsAreScoped(): void
    {
        $get = $this->cases()['GET'];

        $this->assertStringContainsString(
            'AuthMiddleware::requireAuth()',
            $get,
            'GET returns form_data for every family in a program and must authenticate.'
        );
        $this->assertStringContainsString(
            'userCanAccessAthlete',
            $get,
            'The athlete_id branch is the parent portal reading its own child; the '
            . 'staff predicate would lock every family out of their own record.'
        );
        $this->assertStringContainsString(
            'te_is_club_staff(',
            $get,
            'The program_id branch returns the whole program roster and is staff data.'
        );
    }

    /**
     * The public sign-up must stay public. A family registering for a program has
     * no account yet — that is what the registration creates.
     */
    public function testPublicSignUpStaysPublic(): void
    {
        $post = $this->cases()['POST'];

        $this->assertStringNotContainsString(
            'requireAuth',
            $post,
            'The POST branch is the public registration form. Requiring a token there '
            . 'means nobody can register.'
        );
    }
}
