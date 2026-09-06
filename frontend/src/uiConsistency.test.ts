import fs from 'fs';
import path from 'path';

/**
 * ONE page header, ONE table treatment — across the staff app.
 *
 * Maggie, 2026-09-06: "we have different header treatments — an example is
 * Programs and Tournaments — let's fix it so we have one" and "we have
 * different table treatments, we need one." The two treatments are
 * `components/ui/PageHeader.tsx` and `components/ui/DataTable.tsx`; the
 * tally that chose them is in `docs/ui-consistency-inventory-2026-09.md`.
 *
 * ⚠️ This is a SCAN, not a component test, on purpose. The drift lived in
 * ~90 h1s across 30 class-string variants and ~50 tables across three th
 * families. A unit test of one page proves nothing about the next page
 * someone writes — the failure this repo keeps repeating is fixing one file
 * and missing three (`crewEquality.test.ts`, `sameUser.test.ts`,
 * `ParentPortalChildScopeTest` all exist for that shape).
 *
 * What it checks: every file under the STAFF surfaces below renders no raw
 * `<h1` and no raw `<table` — those come from the two components. A file
 * that must, for a stated reason, goes in ALLOWLIST with that reason.
 *
 * What it does NOT check: the parent portal (`parent-portal/`, its own mobile
 * layout), and the PUBLIC / AUTH / PRINT surfaces listed in
 * NOT_STAFF_APP. Those are not the staff app: a login card, a public
 * registration form, a printable lineup and a donor landing page each have
 * a layout of their own, and forcing a staff header onto them would be the
 * wrong kind of consistency. The list is explicit rather than a glob so
 * that a NEW page under `pages/` is a staff page until someone says
 * otherwise.
 */

const SRC = path.join(__dirname);

/** Directories whose non-test .tsx files are staff pages by default. */
const STAFF_DIRS = ['pages', 'modules/registration/pages', 'modules/tournament/pages'];

/**
 * Page-level components routed from App.tsx that live under `components/`.
 * They render a whole staff page (own title, own list), so they are held to
 * the same rule; the rest of `components/` is panels, forms and widgets.
 */
const STAFF_COMPONENTS = [
  'components/AthleteManagement.tsx',
  'components/AthleteProfileEnhanced.tsx',
  'components/CoachManagement.tsx',
  'components/ClubUserManagement.tsx',
  'components/ClubPaymentsSettings.tsx',
  'components/DocumentManager.tsx',
  'components/ExpirationDashboard.tsx',
  'components/InvitationDashboard.tsx',
  'components/RosterManagement.tsx',
  'components/SponsorsManagement.tsx',
  'components/TeamCalendar.tsx',
  'components/TeamCalendarView.tsx',
  'components/TeamList.tsx',
  'components/TeamManagement.tsx',
  'components/VenueManagement.tsx',
  'components/superadmin/AthletesList.tsx',
  'components/superadmin/ClubsList.tsx',
  'components/superadmin/ClubDetails.tsx',
  'components/superadmin/NotificationHealth.tsx',
  'components/superadmin/UsersList.tsx',
  'components/superadmin/UserDetails.tsx',
  // tab panels and modals the Programs / Tournaments pages render their lists through
  'modules/registration/components/TryoutManagement.tsx',
  'modules/tournament/components/RegistrationManager.tsx',
  'modules/tournament/components/DisciplinaryView.tsx',
  'modules/tournament/components/StandingsTable.tsx',
  'modules/tournament/components/RegistrationRosterModal.tsx',
];

/**
 * Files under STAFF_DIRS that are not the staff app. One line each, saying
 * which surface they are. Adding a file here is a statement about the
 * product, not a way past the test.
 */
const NOT_STAFF_APP: Record<string, string> = {
  // auth — centred card on the login flow, no nav, no club context
  'pages/AcceptCoachInvite.tsx': 'auth card',
  'pages/AcceptInvitation.tsx': 'auth card',
  'pages/ForgotPassword.tsx': 'auth card',
  'pages/Login.tsx': 'auth card',
  'pages/ResetPassword.tsx': 'auth card',
  'pages/SetParentPassword.tsx': 'auth card',
  'pages/SignUp.tsx': 'auth card',
  'pages/VerifyMagicLink.tsx': 'auth card',
  'pages/GetStarted.tsx': 'sign-up landing page',
  // public / unauthenticated — families and donors, no staff nav
  'pages/ConsentConfirm.tsx': 'public consent-confirmation page from an email link',
  'pages/ContributePage.tsx': 'public contribution page from a shared link',
  'pages/DonationSuccess.tsx': 'public donation receipt',
  'pages/FundraiserCampaign.tsx': 'public fundraiser landing page (hero image)',
  'pages/PaymentPage.tsx': 'public/family payment checkout',
  'pages/PaymentCheckout.tsx': 'family checkout',
  'pages/MultiPaymentCheckout.tsx': 'family checkout',
  'pages/PaymentReceipt.tsx': 'receipt, printable',
  'pages/DemoPaymentPage.tsx': 'demo checkout for sales walkthroughs',
  'pages/RegistrationCart.tsx': 'family registration cart',
  'pages/WaitlistResponse.tsx': 'public waitlist accept/decline from an email link',
  'pages/PrivacyPolicy.tsx': 'legal document',
  'pages/TermsOfService.tsx': 'legal document',
  'pages/Home.tsx': 'marketing home',
  'modules/registration/pages/PublicRegistration.tsx': 'public registration form',
  'modules/registration/pages/PublicTryoutRegistration.tsx': 'public tryout registration form',
  'modules/tournament/pages/PublicTournament.tsx': 'public tournament page',
  'modules/tournament/pages/PublicLiveScoreboard.tsx': 'public scoreboard',
  // family-facing pages that are NOT in parent-portal/ but serve families
  'pages/AthletePaymentsDashboard.tsx': 'family payments view (parent-facing, legacy route)',
  'pages/FamilyInvoices.tsx': 'family invoices view (parent-facing, legacy route)',
  'pages/MyRequirements.tsx': 'a coach\'s own compliance checklist, personal not club-scoped',
  // print
  'pages/LineupPrintPage.tsx': 'print layout',
};

/**
 * Staff files allowed a raw <h1 or <table, and why. Keep this SHORT.
 * Every entry is a debt with a reason, not a permanent exemption.
 */
const ALLOWLIST: Record<string, string> = {
  'pages/HelpArticlePage.tsx':
    'the <table is a ReactMarkdown element override for tables AUTHORED in help articles — prose, not a data list; the h1 is PageHeader',
};

const isTestFile = (f: string) => /\.test\.tsx?$/.test(f) || f.includes('__tests__');

function listTsx(dir: string): string[] {
  const abs = path.join(SRC, dir);
  if (!fs.existsSync(abs)) return [];
  return fs
    .readdirSync(abs)
    .filter((f) => f.endsWith('.tsx') && !isTestFile(f))
    .map((f) => path.join(dir, f));
}

const staffFiles = () =>
  [...STAFF_DIRS.flatMap(listTsx), ...STAFF_COMPONENTS].filter(
    (f) => !(f in NOT_STAFF_APP) && !(f in ALLOWLIST)
  );

/** Strip comments so a `<table` in a JSDoc block does not count. */
function stripComments(src: string): string {
  return src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
}

describe('UI consistency: one header, one table (staff app)', () => {
  const files = staffFiles();

  it('scans a meaningful number of staff files', () => {
    expect(files.length).toBeGreaterThan(40);
  });

  it.each(files)('%s renders no raw <h1 — use components/ui/PageHeader', (file) => {
    const src = stripComments(fs.readFileSync(path.join(SRC, file), 'utf8'));
    const hits = src.match(/<h1[\s>]/g) ?? [];
    expect(hits).toHaveLength(0);
  });

  it.each(files)('%s has no page title disguised as a text-3xl <h2 — use PageHeader', (file) => {
    // Five routed pages had NO h1: their title was an <h2 className="text-3xl …">.
    // A scan for <h1 alone would have passed them untouched.
    const src = stripComments(fs.readFileSync(path.join(SRC, file), 'utf8'));
    const hits = src.match(/<h2[^>]*className="[^"]*text-3xl/g) ?? [];
    expect(hits).toHaveLength(0);
  });

  it.each(files)('%s renders no raw <table — use components/ui/DataTable', (file) => {
    const src = stripComments(fs.readFileSync(path.join(SRC, file), 'utf8'));
    const hits = src.match(/<table[\s>]/g) ?? [];
    expect(hits).toHaveLength(0);
  });

  it('every NOT_STAFF_APP and ALLOWLIST entry names a file that exists', () => {
    for (const f of [...Object.keys(NOT_STAFF_APP), ...Object.keys(ALLOWLIST)]) {
      expect(fs.existsSync(path.join(SRC, f))).toBe(true);
    }
  });

  it('every STAFF_COMPONENTS entry exists', () => {
    for (const f of STAFF_COMPONENTS) {
      expect(fs.existsSync(path.join(SRC, f))).toBe(true);
    }
  });

  it('the two components are the only files under components/ui that render the raw elements', () => {
    const ui = listTsx('components/ui');
    const read = (f: string) => stripComments(fs.readFileSync(path.join(SRC, f), 'utf8'));
    expect(ui.filter((f) => /<h1[\s>]/.test(read(f)))).toEqual(['components/ui/PageHeader.tsx']);
    expect(ui.filter((f) => /<table[\s>]/.test(read(f)))).toEqual(['components/ui/DataTable.tsx']);
  });
});
