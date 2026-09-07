import fs from 'fs';
import path from 'path';

/**
 * ONE page header, ONE table, ONE button — across the staff app.
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
 * Buttons (2026-09-06, the sweep after headers and tables): no staff page or
 * component renders a raw `<button` carrying a className — those are
 * `components/ui/Button` (or `LinkButton` for navigation). The button scan
 * covers MORE files than the header/table one: every component under
 * `components/` and `modules/x/components/` (minus `components/ui/` and the
 * parent-portal / public-only components in NOT_STAFF_COMPONENTS), because
 * buttons live in modals, forms and panels, not just on pages. A file that
 * keeps a raw button (a tab strip, a segmented toggle, a colour swatch) is in
 * BUTTON_ALLOWLIST with the number it keeps and why.
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

/**
 * Components that are NOT the staff app: rendered only by the parent portal
 * or by a public page. Same rule as NOT_STAFF_APP — a statement about the
 * product, one line each.
 */
const NOT_STAFF_COMPONENTS: Record<string, string> = {
  'components/CampaignProgress.tsx': 'public fundraiser page',
  'components/DonationForm.tsx': 'public fundraiser page',
  'components/DonorWall.tsx': 'public fundraiser page',
  'modules/registration/components/PublicRegistrationForm.tsx': 'public registration form',
  'components/CoachDashboard.tsx': 'legacy dark coach dashboard, not routed from App.tsx',
  'components/AttendanceTracker.tsx': 'legacy coach dashboard panel, not routed from App.tsx',
};

/**
 * Staff files that keep raw `<button className=…>` elements: how many, and
 * why each cannot be a Button. Keep it SHORT; the count is a ceiling so a new
 * raw button in the same file still fails.
 */
const BUTTON_ALLOWLIST: Record<string, { max: number; reason: string }> = {
  // templates / help / comms
  'pages/SmsTemplates.tsx': { max: 8, reason: 'tab strip, category chips, grid/list toggle, merge-field menu rows, quick-message launcher panel' },
  'pages/TemplateLibrary.tsx': { max: 7, reason: 'tab strip, category chips, grid/list toggle' },
  'pages/TemplateEditor.tsx': { max: 4, reason: 'Teams / Merge Tags panel toggles, JSON import/export segmented toggle' },
  'pages/HelpAdmin.tsx': { max: 1, reason: 'tab strip' },
  'pages/CommunicationLog.tsx': { max: 1, reason: 'channel filter segmented toggle' },
  'pages/EmailReporting.tsx': { max: 2, reason: 'email / SMS segmented toggle' },
  'pages/SmsInbox.tsx': { max: 2, reason: 'filter chip; conversation-list row with a selected state' },
  'components/help/HelpSidebar.tsx': { max: 2, reason: 'category accordion header; search-field lookalike that opens the palette' },
  'components/help/HelpFeedback.tsx': { max: 2, reason: 'Yes / No toggle pair with a selected state' },
  'components/help/HelpSearchModal.tsx': { max: 1, reason: 'search result row with keyboard-selected state' },
  // compose / club settings / compliance
  'components/communications/EmailCompose.tsx': { max: 2, reason: 'free-form / template segmented toggle' },
  'components/communications/RecipientSelector.tsx': { max: 5, reason: 'chip-remove × inheriting the chip colour; typeahead listbox rows' },
  'components/communications/CommunicationHistory.tsx': { max: 1, reason: 'expandable entry header row' },
  'pages/ClubDocumentCenter.tsx': { max: 3, reason: 'Upload / Paste-link tab strip; assignment-chip ×' },
  'pages/ClubProfilePage.tsx': { max: 7, reason: 'page tab strip' },
  'pages/ClubCompliance.tsx': { max: 2, reason: 'aria-pressed filter chips; person accordion header row' },
  'pages/ComplianceRequirements.tsx': { max: 1, reason: 'aria-pressed role pill toggles' },
  'pages/OrgCompliance.tsx': { max: 1, reason: 'SortHeader inside a DataTable th' },
  'components/ClubUserManagement.tsx': { max: 3, reason: 'sub-tab segmented control' },
  'components/InviteUsersForm.tsx': { max: 2, reason: 'email / link method toggle cards' },
  'components/InvitationDashboard.tsx': { max: 3, reason: 'status filter segmented control' },
  'components/SignatureEditor.tsx': { max: 1, reason: 'aria-pressed editor toolbar toggle' },
  'components/LogoColorExtractor.tsx': { max: 1, reason: 'colour swatch — the background IS the content' },
  'components/ProfileMenu.tsx': { max: 1, reason: 'dropdown menu item' },
  'components/ClubContextPicker.tsx': { max: 1, reason: 'role="option" listbox rows' },
  // athletes / coaches / crew
  'components/AthleteProfileEnhanced.tsx': { max: 2, reason: 'team-selector cards; profile tab strip' },
  'components/AthleteManagement.tsx': { max: 2, reason: 'column sort-header control; team-picker cards in the add-to-team modal' },
  'pages/CrewRoster.tsx': { max: 1, reason: 'status filter chips' },
  'pages/CoachProfile.tsx': { max: 1, reason: 'tab strip' },
  'components/CoachProfileEdit.tsx': { max: 2, reason: 'URL / Upload segmented toggle' },
  'components/evaluations/AthleteEvaluationsPanel.tsx': { max: 1, reason: 'aria-expanded accordion row header' },
  'components/evaluations/ScoringForm.tsx': { max: 1, reason: '1–5 score selector (aria-pressed rating cells)' },
  // teams / calendar / venues / lineup
  'components/TeamCalendarView.tsx': { max: 4, reason: 'week / month / schedule view toggles; weekday repeat chips' },
  'components/TeamFormWithTabs.tsx': { max: 2, reason: 'Info / Staff tab strip' },
  'components/VenueManagement.tsx': { max: 2, reason: 'collapsible section disclosure rows' },
  'components/RosterDownloadButton.tsx': { max: 2, reason: 'role="menuitem" rows with two-line content' },
  'components/AttendanceModal.tsx': { max: 2, reason: 'per-row status button group — the colour is the state' },
  'components/PracticeScheduler.tsx': { max: 1, reason: 'weekday picker toggles' },
  'components/SmartScheduler.tsx': { max: 1, reason: 'availability-matrix cells' },
  'components/RosterManagement.tsx': { max: 1, reason: 'aria-pressed availability filter pills' },
  'components/lineup/LineupBuilder.tsx': { max: 1, reason: 'aria-pressed player row selector with badges' },
  'components/ExpirationDashboard.tsx': { max: 1, reason: '"next N days" segmented filter' },
  // superadmin / chat / support
  'components/superadmin/ClubDetails.tsx': { max: 3, reason: 'Edit + close × on the dark header band; user-search menu rows' },
  'components/superadmin/UserDetails.tsx': { max: 2, reason: 'Edit + close × on the dark header band' },
  'pages/SuperAdminDashboard.tsx': { max: 1, reason: 'tab strip' },
  'components/chat/NewConversationDialog.tsx': { max: 9, reason: 'dark-header back chevron; chip-remove ×; Browse Teams toggle; checkbox list rows' },
  'components/chat/ConversationList.tsx': { max: 4, reason: 'conversation list rows and their header rows, not commands' },
  'components/chat/ChatWidget.tsx': { max: 3, reason: 'round FAB launcher; close / back icons on the dark header' },
  'components/chat/MessageReactions.tsx': { max: 3, reason: 'aria-pressed reaction pills; round picker trigger; emoji menu items' },
  'components/chat/ReportMessageButton.tsx': { max: 1, reason: 'dropdown menu items' },
  'components/chat/PollMessage.tsx': { max: 1, reason: 'aria-pressed vote option rows with result bars' },
  'pages/ChatModeration.tsx': { max: 1, reason: 'status filter chips' },
  'components/support/SupportButton.tsx': { max: 1, reason: 'fixed round FAB' },
  // registration / volunteers / referee
  'modules/registration/pages/ProgramManagement.tsx': { max: 2, reason: 'program-type tab strip; collapsible section heading' },
  'modules/registration/components/TryoutManagement.tsx': { max: 2, reason: 'tab strip; CoachInviteButton whose amber "invited" state is the tested coachInviteButtonClass' },
  'modules/registration/components/RegistrationsModal.tsx': { max: 1, reason: 'status filter tab strip' },
  'modules/registration/components/EmbedCodeModal.tsx': { max: 3, reason: 'iframe / button segmented toggle; the embed PREVIEW button' },
  'modules/registration/components/ProgramFormBuilder.tsx': { max: 3, reason: 'Details / Form / Schedule tab strip' },
  'pages/VolunteerManagement.tsx': { max: 4, reason: 'status segmented filter; New / Existing toggle; user-search result rows' },
  'pages/VolunteerSignupRequests.tsx': { max: 1, reason: 'status filter segmented control' },
  'components/referee/RefereeFeedbackModal.tsx': { max: 1, reason: 'aria-pressed category chips' },
  // tournaments / fundraisers
  'modules/tournament/components/MatchCenterModal.tsx': { max: 1, reason: 'Score / Report / Notes tab strip' },
  'modules/tournament/components/RegistrationRosterModal.tsx': { max: 2, reason: 'roster / guest player segmented toggle' },
  'modules/tournament/components/RegistrationManager.tsx': { max: 1, reason: 'status-count filter chips' },
  'modules/tournament/pages/TournamentDetail.tsx': { max: 1, reason: 'page tab strip' },
  'modules/tournament/components/GameDayBoard.tsx': { max: 1, reason: 'weather-delay toggle — the colour is its state' },
  'modules/tournament/components/RosterCheckIn.tsx': { max: 1, reason: 'the whole player card is the check-in toggle' },
  'modules/tournament/components/MarkdownEditor.tsx': { max: 1, reason: 'TipTap toolbar buttons with active state' },
  'pages/FundraiserCampaignDashboard.tsx': { max: 2, reason: 'Donations / Updates tab strip' },
  'pages/FundraiserCampaignsList.tsx': { max: 1, reason: 'all / active / draft / ended segmented filter' },
};

/** Directories (recursive) whose non-test .tsx files are scanned for raw buttons. */
const BUTTON_DIRS = ['components', 'modules/registration/components', 'modules/tournament/components'];

const isTestFile = (f: string) => /\.test\.tsx?$/.test(f) || f.includes('__tests__');

function listTsx(dir: string): string[] {
  const abs = path.join(SRC, dir);
  if (!fs.existsSync(abs)) return [];
  return fs
    .readdirSync(abs)
    .filter((f) => f.endsWith('.tsx') && !isTestFile(f))
    .map((f) => path.join(dir, f));
}

function listTsxRecursive(dir: string): string[] {
  const abs = path.join(SRC, dir);
  if (!fs.existsSync(abs)) return [];
  const out: string[] = [];
  for (const entry of fs.readdirSync(abs, { withFileTypes: true })) {
    const rel = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === '__tests__' || rel === 'components/ui') continue;
      out.push(...listTsxRecursive(rel));
    } else if (entry.name.endsWith('.tsx') && !isTestFile(entry.name)) {
      out.push(rel);
    }
  }
  return out;
}

/**
 * Opening `<button …>` tags, read with brace and quote awareness — a `>`
 * inside `onClick={() => …}` is not the end of the tag. Returns each tag's
 * attribute text.
 */
export function openingButtonTags(src: string): string[] {
  const out: string[] = [];
  const re = /<button(?=[\s/>])/g;
  while (re.exec(src)) {
    let i = re.lastIndex;
    let depth = 0;
    let quote: string | null = null;
    for (; i < src.length; i++) {
      const c = src[i];
      if (quote) {
        if (c === '\\') i++;
        else if (c === quote) quote = null;
        continue;
      }
      if (c === '"' || c === "'" || c === '`') quote = c;
      else if (c === '{') depth++;
      else if (c === '}') depth--;
      else if (c === '>' && depth === 0) break;
    }
    out.push(src.slice(re.lastIndex, i));
  }
  return out;
}

const rawStyledButtons = (src: string) =>
  openingButtonTags(src).filter((attrs) => /\bclassName=/.test(attrs));

const buttonFiles = () =>
  [...STAFF_DIRS.flatMap(listTsx), ...BUTTON_DIRS.flatMap(listTsxRecursive)]
    .filter((f, i, all) => all.indexOf(f) === i)
    .filter((f) => !(f in NOT_STAFF_APP) && !(f in NOT_STAFF_COMPONENTS));

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

  describe('one button', () => {
    const bfiles = buttonFiles();

    it('scans a meaningful number of staff files for buttons', () => {
      expect(bfiles.length).toBeGreaterThan(150);
    });

    it.each(bfiles)('%s renders no raw <button className — use components/ui/Button', (file) => {
      const src = stripComments(fs.readFileSync(path.join(SRC, file), 'utf8'));
      const hits = rawStyledButtons(src);
      const ceiling = BUTTON_ALLOWLIST[file]?.max ?? 0;
      expect(hits.length).toBeLessThanOrEqual(ceiling);
    });

    it('every NOT_STAFF_COMPONENTS and BUTTON_ALLOWLIST entry names a file that exists and is in scope', () => {
      for (const f of Object.keys(NOT_STAFF_COMPONENTS)) {
        expect(fs.existsSync(path.join(SRC, f))).toBe(true);
      }
      for (const f of Object.keys(BUTTON_ALLOWLIST)) {
        expect(bfiles).toContain(f);
      }
    });

    it('Button is the only file under components/ui that renders a raw <button className', () => {
      const ui = listTsx('components/ui');
      const read = (f: string) => stripComments(fs.readFileSync(path.join(SRC, f), 'utf8'));
      expect(ui.filter((f) => rawStyledButtons(read(f)).length > 0)).toEqual([
        'components/ui/Button.tsx',
        'components/ui/DataTable.tsx', // the sortable-header control; a Button would restyle the th
      ]);
    });

    it('the tag reader does not stop at a > inside an onClick arrow', () => {
      const src = '<button onClick={() => go(1 > 0)} className="x">a</button><button type="button">b</button>';
      expect(openingButtonTags(src)).toHaveLength(2);
      expect(rawStyledButtons(src)).toHaveLength(1);
    });
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
