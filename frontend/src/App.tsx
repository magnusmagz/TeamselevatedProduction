import React from 'react';
import { BrowserRouter as Router, Routes, Route, Link, Navigate, useLocation } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { OrgProvider, useOrg } from './contexts/OrgContext';
import { ThemeProvider } from './contexts/ThemeContext';
// LeagueSelector removed - clubs are now the top-level entity
import BrandingLogo from './components/BrandingLogo';
import ErrorBoundary from './components/ErrorBoundary';
import ProfileMenu from './components/ProfileMenu';
import ProtectedRoute from './components/ProtectedRoute';
import ProtectedFinancialRoute from './components/ProtectedFinancialRoute';
import ProtectedSuperAdminRoute from './components/ProtectedSuperAdminRoute';
import SuperAdminDashboard from './pages/SuperAdminDashboard';
import { ChatWidget } from './components/chat';
import { useModerationOpenCount } from './hooks/useModerationOpenCount';
import EnableNotificationsPrompt from './components/EnableNotificationsPrompt';
import { SupportButton } from './components/support/SupportButton';
import { recordPageVisit } from './components/support/pageHistory';
import Home from './pages/Home';
import Login from './pages/Login';
import SignUp from './pages/SignUp';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';
import SetParentPassword from './pages/SetParentPassword';
import VerifyMagicLink from './pages/VerifyMagicLink';
import GetStarted from './pages/GetStarted';
import PrivacyPolicy from './pages/PrivacyPolicy';
import TermsOfService from './pages/TermsOfService';
import WaitlistResponse from './pages/WaitlistResponse';
import ConsentConfirm from './pages/ConsentConfirm';
import TeamManagement from './components/TeamManagement';
import AthleteProfileEnhanced from './components/AthleteProfileEnhanced';
import AthleteManagement from './components/AthleteManagement';
import CoachManagement from './components/CoachManagement';
import VenueManagement from './components/VenueManagement';
import SponsorsManagement from './components/SponsorsManagement';
import ClubProfilePage from './pages/ClubProfilePage';
import TeamCalendar from './components/TeamCalendar';
import RosterManagement from './components/RosterManagement';
import ProgramManagement from './modules/registration/pages/ProgramManagement';
import PublicRegistration from './modules/registration/pages/PublicRegistration';
import DocumentManager from './components/DocumentManager';
import ExpirationDashboard from './components/ExpirationDashboard';
import Invitations from './pages/Invitations';
import AcceptInvitation from './pages/AcceptInvitation';
// LeagueSettings removed - use ClubProfilePage for club settings
import UserProfile from './pages/UserProfile';
import CoachProfile from './pages/CoachProfile';
import { useParams } from 'react-router-dom';
// Payment components
import { DemoModeBanner } from './components/DemoModeBanner';
import { ImpersonationBanner } from './components/ImpersonationBanner';
import { RevenueDashboard } from './pages/RevenueDashboard';
import { StaffDashboard } from './pages/StaffDashboard';
import { PaymentItemsList } from './pages/PaymentItemsList';
import { AthletePaymentsDashboard } from './pages/AthletePaymentsDashboard';
import { PaymentCheckout } from './pages/PaymentCheckout';
import { PaymentReceipt } from './pages/PaymentReceipt';
import { TransactionReport } from './pages/TransactionReport';
import { OutstandingBalances } from './pages/OutstandingBalances';
import { RegistrationCart } from './pages/RegistrationCart';
import { MultiPaymentCheckout } from './pages/MultiPaymentCheckout';
import { RosterFeeStatus } from './pages/RosterFeeStatus';
import { FamilyInvoices } from './pages/FamilyInvoices';
import { DemoPaymentPage } from './pages/DemoPaymentPage';
import { PaymentPage } from './pages/PaymentPage';
import { TeamDetailPage } from './pages/TeamDetailPage';
import TeamCalendarPage from './pages/TeamCalendarPage';
import { RegistrationCartProvider } from './contexts/RegistrationCartContext';
// Fundraiser Campaign pages
import { ContributePage } from './pages/ContributePage';
import { FundraiserCampaign } from './pages/FundraiserCampaign';
import { DonationSuccess } from './pages/DonationSuccess';
import { FundraiserCampaignsList } from './pages/FundraiserCampaignsList';
import { FundraiserCampaignForm } from './pages/FundraiserCampaignForm';
import { FundraiserCampaignDashboard } from './pages/FundraiserCampaignDashboard';
// Tournament Module
import TournamentList from './modules/tournament/pages/TournamentList';
import TournamentCreate from './modules/tournament/pages/TournamentCreate';
import TournamentDetail from './modules/tournament/pages/TournamentDetail';
import PublicTournament from './modules/tournament/pages/PublicTournament';
import PublicLiveScoreboard from './modules/tournament/pages/PublicLiveScoreboard';
// Player Cards
import PlayerCards from './pages/PlayerCards';
// Communications & Email
import CommunicationLog from './pages/CommunicationLog';
import BroadcastCompose from './pages/BroadcastCompose';
import SmsInbox from './pages/SmsInbox';
import TemplateLibrary from './pages/TemplateLibrary';
import CrewRoster from './pages/CrewRoster';
import ProtectedClubAdminRoute from './components/ProtectedClubAdminRoute';
import TemplateEditor from './pages/TemplateEditor';
import EmailReporting from './pages/EmailReporting';
import DataImport from './pages/DataImport';
import ImportsIndex from './pages/ImportsIndex';
import SmsTemplates from './pages/SmsTemplates';
import ChatModeration from './pages/ChatModeration';
// Volunteer Management
import VolunteerManagement from './pages/VolunteerManagement';
import VolunteerSignupRequests from './pages/VolunteerSignupRequests';
import ComplianceDashboard from './pages/ComplianceDashboard';
import ClubDocumentCenter from './pages/ClubDocumentCenter';
// Help Portal
import HelpPortal from './pages/HelpPortal';
import HelpArticlePage from './pages/HelpArticlePage';
import ReleaseNotes from './pages/ReleaseNotes';
import ReleaseNotePage from './pages/ReleaseNotePage';
import HelpAdmin from './pages/HelpAdmin';
import HelpCategoryPage from './pages/HelpCategoryPage';
// Parent Portal
import { FinancialPermissionsProvider } from './contexts/FinancialPermissionsContext';
import { ProtectedParentRoute } from './components/ProtectedParentRoute';
import { ParentRedirect } from './components/ParentRedirect';
import { InstallPrompt } from './parent-portal/components/InstallPrompt';
import { ParentPortalLayout } from './parent-portal/ParentPortalLayout';
import { ParentDashboard } from './parent-portal/ParentDashboard';
import { MyAthletesPage } from './parent-portal/pages/MyAthletesPage';
import { AthleteDetailPage } from './parent-portal/pages/AthleteDetailPage';
import { PaymentStatusPage } from './parent-portal/pages/PaymentStatusPage';
import { MakePaymentPage } from './parent-portal/pages/MakePaymentPage';
import { UpcomingEventsPage } from './parent-portal/pages/UpcomingEventsPage';
import { ScheduleRSVPPage } from './parent-portal/pages/ScheduleRSVPPage';
import { TeamChatPage } from './parent-portal/pages/TeamChatPage';
import { AnnouncementsPage } from './parent-portal/pages/AnnouncementsPage';
import { DocumentsPage } from './parent-portal/pages/DocumentsPage';
import { MedicalInfoPage } from './parent-portal/pages/MedicalInfoPage';
import { MoreMenuPage } from './parent-portal/pages/MoreMenuPage';
import { VolunteerPage } from './parent-portal/pages/VolunteerPage';

// Fundraiser Admin Wrapper Component
const FundraiserAdminWrapper: React.FC<{ children: (props: { clubId: number; clubSlug: string; userId: number }) => React.ReactNode }> = ({ children }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user } = useAuth();
  const { currentClubId } = useOrg();
  const [clubSlug, setClubSlug] = React.useState<string>('');
  const [loading, setLoading] = React.useState(true);

  React.useEffect(() => {
    const fetchClubSlug = async () => {
      if (!currentClubId) return;
      try {
        const response = await fetch(`${API_URL}/api/clubs.php?action=get&id=${currentClubId}`);
        const data = await response.json();
        if (data.slug) {
          setClubSlug(data.slug);
        }
      } catch (error) {
        console.error('Error fetching club slug:', error);
      } finally {
        setLoading(false);
      }
    };
    fetchClubSlug();
  }, [currentClubId, API_URL]);

  if (!currentClubId || !user) {
    return (
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-gray-500 py-12">Please select a club to manage fundraisers.</div>
      </main>
    );
  }

  if (loading) {
    return (
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-brand-primary py-12">Loading...</div>
      </main>
    );
  }

  return <>{children({ clubId: currentClubId, clubSlug, userId: user.id })}</>;
};

/**
 * Athlete documents route.
 *
 * The route element used to read the id with
 * `window.location.pathname.split('/')[2]`, which is a positional guess at the
 * URL rather than the route's own parameter: it silently returns the wrong
 * segment if the path ever gains a prefix, and it is not reactive, so a
 * client-side navigation between two athletes re-rendered with the OLD id.
 * `useParams` is the route's own answer.
 */
const AthleteDocumentsPage: React.FC = () => {
  const { athleteId } = useParams<{ athleteId: string }>();
  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <DocumentManager athleteId={athleteId || ''} />
    </main>
  );
};

// Team Roster Page Component
const TeamRosterPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { teamId } = useParams<{ teamId: string }>();
  const [team, setTeam] = React.useState<{ id: number; name: string; age_group?: string } | null>(null);
  const [loading, setLoading] = React.useState(true);

  React.useEffect(() => {
    const fetchTeam = async () => {
      try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(`${API_URL}/legacy/teams-gateway.php?id=${teamId}`, {
          headers: {
            'Authorization': `Bearer ${token}`
          }
        });
        const data = await response.json();
        if (data.id && data.name) {
          setTeam({ id: data.id, name: data.name, age_group: data.age_group });
        }
      } catch (error) {
        console.error('Error fetching team:', error);
      } finally {
        setLoading(false);
      }
    };

    if (teamId) {
      fetchTeam();
    }
  }, [teamId]);

  if (loading) {
    return (
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-brand-primary py-12">Loading team...</div>
      </main>
    );
  }

  if (!team) {
    return (
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-brand-primary py-12">Team not found</div>
      </main>
    );
  }

  return (
    <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <RosterManagement team={team} />
    </main>
  );
};

function AppContent() {
  const { user } = useAuth();
  const { isClubAdmin, currentClubId } = useOrg();
  const location = useLocation();
  const [mobileMenuOpen, setMobileMenuOpen] = React.useState(false);

  // Determine if user has admin capabilities (super_admin always gets full admin view)
  const isAdmin = isClubAdmin || user?.system_role === 'super_admin';

  // Reported-messages badge. Admin-only; the endpoint enforces that server-side
  // too, since a client flag is not an access control.
  const moderationCount = useModerationOpenCount(isAdmin, currentClubId);
  const openReports = moderationCount?.openTotal ?? 0;

  // Hide floating chat widget on parent portal (has its own chat in bottom nav)
  const isParentPortal = location.pathname.startsWith('/parent');

  // Close mobile menu on route change
  React.useEffect(() => {
    setMobileMenuOpen(false);
  }, [location.pathname]);

  // Breadcrumb trail for support tickets — "what were you doing before this?"
  // answered without asking. Recorded here, in the one component every route
  // renders inside, so the staff app and the parent portal are both covered by
  // a single call. Redaction and the cap live in pageHistory.ts.
  React.useEffect(() => {
    recordPageVisit(location.pathname + location.search);
  }, [location.pathname, location.search]);

  const [peopleDropdownOpen, setPeopleDropdownOpen] = React.useState(false);
  const peopleDropdownRef = React.useRef<HTMLDivElement>(null);

  // Close People dropdown on click outside
  React.useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (peopleDropdownRef.current && !peopleDropdownRef.current.contains(e.target as Node)) {
        setPeopleDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Close People dropdown on route change
  React.useEffect(() => {
    setPeopleDropdownOpen(false);
  }, [location.pathname]);

  const peopleLinks = isAdmin
    ? [
        { to: '/athletes', label: 'Athletes' },
        { to: '/crew', label: 'Crew' },
        { to: '/coaches', label: 'Coaches' },
        { to: '/volunteers', label: 'Volunteers' },
      ]
    : [
        { to: '/athletes', label: 'Athletes' },
        { to: '/volunteers', label: 'Volunteers' },
      ];

  const isPeopleActive = peopleLinks.some((link) => location.pathname === link.to);

  const [programsDropdownOpen, setProgramsDropdownOpen] = React.useState(false);
  const programsDropdownRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (programsDropdownRef.current && !programsDropdownRef.current.contains(e.target as Node)) {
        setProgramsDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  React.useEffect(() => {
    setProgramsDropdownOpen(false);
  }, [location.pathname]);

  const programsLinks = [
    { to: '/program-management', label: 'Programs' },
    { to: '/tournaments', label: 'Tournaments' },
  ];

  const isProgramsActive = programsLinks.some((link) => location.pathname.startsWith(link.to));

  const [amplifiersDropdownOpen, setAmplifiersDropdownOpen] = React.useState(false);
  const amplifiersDropdownRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (amplifiersDropdownRef.current && !amplifiersDropdownRef.current.contains(e.target as Node)) {
        setAmplifiersDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  React.useEffect(() => {
    setAmplifiersDropdownOpen(false);
  }, [location.pathname]);

  const amplifiersLinks = [
    { to: '/sponsors', label: 'Sponsors' },
    { to: '/admin/fundraisers', label: 'Fundraisers' },
  ];

  const isAmplifiersActive = amplifiersLinks.some((link) => location.pathname.startsWith(link.to));

  const [documentsDropdownOpen, setDocumentsDropdownOpen] = React.useState(false);
  const documentsDropdownRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (documentsDropdownRef.current && !documentsDropdownRef.current.contains(e.target as Node)) {
        setDocumentsDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  React.useEffect(() => {
    setDocumentsDropdownOpen(false);
  }, [location.pathname]);

  // The expiration dashboard had a route and no way to reach it — nothing in the
  // app linked to /documents/expiring, so the only arrival was a typed URL.
  // Documents becomes a dropdown so it sits beside the Document Center under the
  // same admin menu, and both entries share /club-documents' admin guard.
  const documentsLinks = [
    { to: '/club-documents', label: 'Document Center' },
    { to: '/documents/expiring', label: 'Expiring Soon' },
  ];

  const isDocumentsActive = documentsLinks.some((link) => location.pathname.startsWith(link.to));

  const [commsDropdownOpen, setCommsDropdownOpen] = React.useState(false);
  const commsDropdownRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (commsDropdownRef.current && !commsDropdownRef.current.contains(e.target as Node)) {
        setCommsDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  React.useEffect(() => {
    setCommsDropdownOpen(false);
  }, [location.pathname]);

  // `exact` matters for '/communications': every other comms route is nested under
  // it, so a prefix match would light up "All" on all of them.
  const commsLinks = [
    { to: '/communications', label: 'All', exact: true },
    { to: '/communications/broadcast', label: 'Broadcast' },
    { to: '/communications/inbox', label: 'Inbox' },
    { to: '/email-templates', label: 'Email Templates' },
    { to: '/sms-templates', label: 'SMS Templates' },
    { to: '/email-reporting', label: 'Reporting' },
    { to: '/chat-moderation', label: 'Reported Messages' },
  ];

  const isCommsLinkActive = (link: { to: string; exact?: boolean }) =>
    link.exact ? location.pathname === link.to : location.pathname.startsWith(link.to);

  const isCommsActive = commsLinks.some(isCommsLinkActive);

  // `/dashboard` is the overview (StaffDashboard); Teams has its own route at
  // `/teams`. ⚠️ The People dropdown is injected positionally, after the Teams
  // link — the two `link.to === '/teams'` checks in the desktop and mobile navs
  // below have to name whatever route the Teams entry here points at, or the
  // dropdown moves (or disappears) silently.
  const navLinks = isAdmin ? [
    { to: '/dashboard', label: 'Home' },
    { to: '/payment/revenue', label: 'Revenue' },
    { to: '/__programs_dropdown__', label: 'Programs' },
    { to: '/teams', label: 'Teams' },
    { to: '/__comms_dropdown__', label: 'Communications' },
    { to: '/calendar', label: 'Calendar' },
    { to: '/venues', label: 'Facilities' },
    { to: '/__documents_dropdown__', label: 'Documents' },
    // Bulk import lives under Club Settings → Imports now (was a top-level
    // nav item). Route /imports still resolves for any saved bookmarks.
    { to: '/__amplifiers_dropdown__', label: 'Amplifiers' },
    ...(user?.system_role === 'super_admin' ? [{ to: '/super-admin', label: 'Platform Admin' }] : []),
  ] : [
    { to: '/dashboard', label: 'Home' },
    { to: '/teams', label: 'My Teams' },
    { to: '/__programs_dropdown__', label: 'Programs' },
    { to: '/__comms_dropdown__', label: 'Communications' },
    { to: '/calendar', label: 'Calendar' },
    ...(user?.system_role === 'super_admin' ? [{ to: '/super-admin', label: 'Platform Admin' }] : []),
  ];

  return (
    <div className="min-h-screen bg-white">
        <ImpersonationBanner />
        <DemoModeBanner />
        {/* Install prompt for the main app shell only. The parent portal renders
            its own InstallPrompt inside ParentPortalLayout, so it is excluded
            here via !isParentPortal to avoid a double prompt. */}
        {user && !isParentPortal && <InstallPrompt />}
        {/* Same prompt for staff. Coaches need this more than anyone — a parent
            messaging about a cancellation is time-sensitive. */}
        {user && !isParentPortal && <EnableNotificationsPrompt />}
        {user && !isParentPortal && (
          <nav className="bg-white border-b border-brand-secondary">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              {/* Top row: Logo and user controls */}
              <div className="flex justify-between items-center h-20 border-b border-brand-secondary">
                <Link to="/dashboard" className="flex items-center">
                  {location.pathname === '/super-admin' ? (
                    <span className="text-lg font-bold text-brand-primary uppercase tracking-wide">TEAMS ELEVATED</span>
                  ) : (
                    <BrandingLogo size="xl" fallbackToText={true} />
                  )}
                </Link>
                <div className="flex items-center space-x-4">
                  {/* Mobile hamburger button */}
                  <button
                    onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                    className="md:hidden w-11 h-11 flex items-center justify-center text-brand-primary"
                    aria-label="Toggle navigation menu"
                  >
                    {mobileMenuOpen ? (
                      <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    ) : (
                      <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                      </svg>
                    )}
                  </button>
                  <Link
                    to="/help"
                    className="hidden md:flex w-9 h-9 items-center justify-center rounded-full text-brand-primary hover:bg-brand-secondary/30 transition-colors"
                    title="Help & Docs"
                  >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </Link>
                  <ProfileMenu />
                </div>
              </div>

              {/* Desktop navigation */}
              <div className="hidden md:flex space-x-6 h-12 items-center">
                {navLinks.flatMap((link) => {
                  // Programs dropdown
                  if (link.to === '/__programs_dropdown__') {
                    return [
                      <div key="programs-dropdown" className="relative" ref={programsDropdownRef}>
                        <button
                          onClick={() => { setProgramsDropdownOpen(!programsDropdownOpen); setPeopleDropdownOpen(false); setAmplifiersDropdownOpen(false); setCommsDropdownOpen(false); setDocumentsDropdownOpen(false); }}
                          className={`uppercase font-medium text-sm flex items-center gap-1 ${
                            isProgramsActive
                              ? 'text-brand-primary border-b-2 border-brand-primary'
                              : 'text-brand-primary hover:text-brand-primary-hover'
                          }`}
                        >
                          Programs
                          <svg className={`w-3.5 h-3.5 transition-transform ${programsDropdownOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                          </svg>
                        </button>
                        {programsDropdownOpen && (
                          <div className="absolute top-full left-0 mt-1 bg-white border border-brand-secondary rounded-lg shadow-lg py-1 min-w-[160px] z-50">
                            {programsLinks.map((pLink) => (
                              <Link
                                key={pLink.to}
                                to={pLink.to}
                                className={`block px-4 py-2 text-sm font-medium ${
                                  location.pathname.startsWith(pLink.to)
                                    ? 'text-brand-primary bg-brand-secondary'
                                    : 'text-brand-primary hover:bg-brand-secondary'
                                }`}
                              >
                                {pLink.label}
                              </Link>
                            ))}
                          </div>
                        )}
                      </div>
                    ];
                  }

                  // Amplifiers dropdown
                  if (link.to === '/__amplifiers_dropdown__') {
                    return [
                      <div key="amplifiers-dropdown" className="relative" ref={amplifiersDropdownRef}>
                        <button
                          onClick={() => { setAmplifiersDropdownOpen(!amplifiersDropdownOpen); setPeopleDropdownOpen(false); setProgramsDropdownOpen(false); setCommsDropdownOpen(false); setDocumentsDropdownOpen(false); }}
                          className={`uppercase font-medium text-sm flex items-center gap-1 ${
                            isAmplifiersActive
                              ? 'text-brand-primary border-b-2 border-brand-primary'
                              : 'text-brand-primary hover:text-brand-primary-hover'
                          }`}
                        >
                          Amplifiers
                          <svg className={`w-3.5 h-3.5 transition-transform ${amplifiersDropdownOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                          </svg>
                        </button>
                        {amplifiersDropdownOpen && (
                          <div className="absolute top-full left-0 mt-1 bg-white border border-brand-secondary rounded-lg shadow-lg py-1 min-w-[160px] z-50">
                            {amplifiersLinks.map((aLink) => (
                              <Link
                                key={aLink.to}
                                to={aLink.to}
                                className={`block px-4 py-2 text-sm font-medium ${
                                  location.pathname.startsWith(aLink.to)
                                    ? 'text-brand-primary bg-brand-secondary'
                                    : 'text-brand-primary hover:bg-brand-secondary'
                                }`}
                              >
                                {aLink.label}
                              </Link>
                            ))}
                          </div>
                        )}
                      </div>
                    ];
                  }

                  // Documents dropdown
                  if (link.to === '/__documents_dropdown__') {
                    return [
                      <div key="documents-dropdown" className="relative" ref={documentsDropdownRef}>
                        <button
                          onClick={() => { setDocumentsDropdownOpen(!documentsDropdownOpen); setPeopleDropdownOpen(false); setProgramsDropdownOpen(false); setAmplifiersDropdownOpen(false); setCommsDropdownOpen(false); }}
                          className={`uppercase font-medium text-sm flex items-center gap-1 ${
                            isDocumentsActive
                              ? 'text-brand-primary border-b-2 border-brand-primary'
                              : 'text-brand-primary hover:text-brand-primary-hover'
                          }`}
                        >
                          Documents
                          <svg className={`w-3.5 h-3.5 transition-transform ${documentsDropdownOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                          </svg>
                        </button>
                        {documentsDropdownOpen && (
                          <div className="absolute top-full left-0 mt-1 bg-white border border-brand-secondary rounded-lg shadow-lg py-1 min-w-[180px] z-50">
                            {documentsLinks.map((dLink) => (
                              <Link
                                key={dLink.to}
                                to={dLink.to}
                                className={`block px-4 py-2 text-sm font-medium ${
                                  location.pathname.startsWith(dLink.to)
                                    ? 'text-brand-primary bg-brand-secondary'
                                    : 'text-brand-primary hover:bg-brand-secondary'
                                }`}
                              >
                                {dLink.label}
                              </Link>
                            ))}
                          </div>
                        )}
                      </div>
                    ];
                  }

                  // Communications dropdown
                  if (link.to === '/__comms_dropdown__') {
                    return [
                      <div key="comms-dropdown" className="relative" ref={commsDropdownRef}>
                        <button
                          onClick={() => { setCommsDropdownOpen(!commsDropdownOpen); setPeopleDropdownOpen(false); setProgramsDropdownOpen(false); setAmplifiersDropdownOpen(false); setDocumentsDropdownOpen(false); }}
                          className={`uppercase font-medium text-sm flex items-center gap-1 ${
                            isCommsActive
                              ? 'text-brand-primary border-b-2 border-brand-primary'
                              : 'text-brand-primary hover:text-brand-primary-hover'
                          }`}
                        >
                          Communications
                          <svg className={`w-3.5 h-3.5 transition-transform ${commsDropdownOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                          </svg>
                        </button>
                        {commsDropdownOpen && (
                          <div className="absolute top-full left-0 mt-1 bg-white border border-brand-secondary rounded-lg shadow-lg py-1 min-w-[180px] z-50">
                            {commsLinks.map((cLink) => (
                              <Link
                                key={cLink.to}
                                to={cLink.to}
                                className={`flex items-center justify-between px-4 py-2 text-sm font-medium ${
                                  isCommsLinkActive(cLink)
                                    ? 'text-brand-primary bg-brand-secondary'
                                    : 'text-brand-primary hover:bg-brand-secondary'
                                }`}
                              >
                                <span>{cLink.label}</span>
                                {cLink.to === '/chat-moderation' && openReports > 0 && (
                                  <span
                                    className="ml-3 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-600 text-white text-xs font-semibold"
                                    aria-label={`${openReports} reported ${openReports === 1 ? 'message' : 'messages'} waiting for review`}
                                  >
                                    {openReports > 99 ? '99+' : openReports}
                                  </span>
                                )}
                              </Link>
                            ))}
                          </div>
                        )}
                      </div>
                    ];
                  }

                  // Regular link + People dropdown after Teams
                  const elements = [
                    <Link
                      key={link.to}
                      to={link.to}
                      className={`uppercase font-medium text-sm ${
                        location.pathname === link.to
                          ? 'text-brand-primary border-b-2 border-brand-primary'
                          : 'text-brand-primary hover:text-brand-primary-hover'
                      }`}
                    >
                      {link.label}
                    </Link>
                  ];
                  if (link.to === '/teams') {
                    elements.push(
                      <div key="people-dropdown" className="relative" ref={peopleDropdownRef}>
                        <button
                          onClick={() => { setPeopleDropdownOpen(!peopleDropdownOpen); setProgramsDropdownOpen(false); setAmplifiersDropdownOpen(false); setDocumentsDropdownOpen(false); }}
                          className={`uppercase font-medium text-sm flex items-center gap-1 ${
                            isPeopleActive
                              ? 'text-brand-primary border-b-2 border-brand-primary'
                              : 'text-brand-primary hover:text-brand-primary-hover'
                          }`}
                        >
                          People
                          <svg className={`w-3.5 h-3.5 transition-transform ${peopleDropdownOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                          </svg>
                        </button>
                        {peopleDropdownOpen && (
                          <div className="absolute top-full left-0 mt-1 bg-white border border-brand-secondary rounded-lg shadow-lg py-1 min-w-[160px] z-50">
                            {peopleLinks.map((pLink) => (
                              <Link
                                key={pLink.to}
                                to={pLink.to}
                                className={`block px-4 py-2 text-sm font-medium ${
                                  location.pathname === pLink.to
                                    ? 'text-brand-primary bg-brand-secondary'
                                    : 'text-brand-primary hover:bg-brand-secondary'
                                }`}
                              >
                                {pLink.label}
                              </Link>
                            ))}
                          </div>
                        )}
                      </div>
                    );
                  }
                  return elements;
                })}
              </div>

              {/* Mobile navigation drawer */}
              {mobileMenuOpen && (
                <div className="md:hidden border-t border-brand-secondary">
                  <div className="py-2 space-y-1">
                    {navLinks.flatMap((link) => {
                      // Programs dropdown → expand flat in mobile
                      if (link.to === '/__programs_dropdown__') {
                        return [
                          <div key="programs-mobile-label" className="px-3 pt-3 pb-1 text-xs text-gray-400 uppercase tracking-wider font-semibold">Programs</div>,
                          ...programsLinks.map((pLink) => (
                            <Link
                              key={pLink.to}
                              to={pLink.to}
                              onClick={() => setMobileMenuOpen(false)}
                              className={`block px-6 py-3 uppercase font-medium text-sm ${
                                location.pathname.startsWith(pLink.to)
                                  ? 'text-brand-primary bg-brand-secondary'
                                  : 'text-brand-primary hover:bg-brand-secondary'
                              }`}
                            >
                              {pLink.label}
                            </Link>
                          ))
                        ];
                      }

                      // Amplifiers dropdown → expand flat in mobile
                      if (link.to === '/__amplifiers_dropdown__') {
                        return [
                          <div key="amplifiers-mobile-label" className="px-3 pt-3 pb-1 text-xs text-gray-400 uppercase tracking-wider font-semibold">Amplifiers</div>,
                          ...amplifiersLinks.map((aLink) => (
                            <Link
                              key={aLink.to}
                              to={aLink.to}
                              onClick={() => setMobileMenuOpen(false)}
                              className={`block px-6 py-3 uppercase font-medium text-sm ${
                                location.pathname.startsWith(aLink.to)
                                  ? 'text-brand-primary bg-brand-secondary'
                                  : 'text-brand-primary hover:bg-brand-secondary'
                              }`}
                            >
                              {aLink.label}
                            </Link>
                          ))
                        ];
                      }

                      // Documents dropdown → expand flat in mobile
                      if (link.to === '/__documents_dropdown__') {
                        return [
                          <div key="documents-mobile-label" className="px-3 pt-3 pb-1 text-xs text-gray-400 uppercase tracking-wider font-semibold">Documents</div>,
                          ...documentsLinks.map((dLink) => (
                            <Link
                              key={dLink.to}
                              to={dLink.to}
                              onClick={() => setMobileMenuOpen(false)}
                              className={`block px-6 py-3 uppercase font-medium text-sm ${
                                location.pathname.startsWith(dLink.to)
                                  ? 'text-brand-primary bg-brand-secondary'
                                  : 'text-brand-primary hover:bg-brand-secondary'
                              }`}
                            >
                              {dLink.label}
                            </Link>
                          ))
                        ];
                      }

                      const items = [
                        <Link
                          key={link.to}
                          to={link.to}
                          onClick={() => setMobileMenuOpen(false)}
                          className={`block px-3 py-3 uppercase font-medium text-sm ${
                            location.pathname === link.to
                              ? 'text-brand-primary bg-brand-secondary'
                              : 'text-brand-primary hover:bg-brand-secondary'
                          }`}
                        >
                          {link.label}
                        </Link>
                      ];
                      if (link.to === '/teams') {
                        items.push(
                          <div key="people-mobile-label" className="px-3 pt-3 pb-1 text-xs text-gray-400 uppercase tracking-wider font-semibold">People</div>
                        );
                        peopleLinks.forEach((pLink) => {
                          items.push(
                            <Link
                              key={pLink.to}
                              to={pLink.to}
                              onClick={() => setMobileMenuOpen(false)}
                              className={`block px-6 py-3 uppercase font-medium text-sm ${
                                location.pathname === pLink.to
                                  ? 'text-brand-primary bg-brand-secondary'
                                  : 'text-brand-primary hover:bg-brand-secondary'
                              }`}
                            >
                              {pLink.label}
                            </Link>
                          );
                        });
                      }
                      return items;
                    }).map((item) => item)}
                  </div>
                </div>
              )}
            </div>
          </nav>
        )}

        {!user && window.location.pathname === '/' && (
          <nav className="bg-white border-b border-brand-secondary">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              <div className="flex justify-between h-16">
                <div className="flex items-center">
                  <Link to="/" className="flex items-center">
                    <span className="text-lg font-bold text-brand-primary uppercase tracking-wide">TEAMS ELEVATED</span>
                  </Link>
                </div>
                <div className="flex items-center">
                  <Link
                    to="/login"
                    className="text-brand-primary hover:text-brand-primary-hover uppercase font-semibold"
                  >
                    LOG IN
                  </Link>
                </div>
              </div>
            </div>
          </nav>
        )}

        <Routes>
          {/* Public routes */}
          <Route path="/" element={user ? <Navigate to="/dashboard" replace /> : <Home />} />
          <Route path="/get-started" element={<GetStarted />} />
          <Route path="/login" element={<Login />} />
          <Route path="/signup" element={<SignUp />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />
          <Route path="/set-parent-password" element={<SetParentPassword />} />
          <Route path="/verify-magic-link" element={<VerifyMagicLink />} />
          <Route path="/privacy-policy" element={<PrivacyPolicy />} />
          <Route path="/terms-of-service" element={<TermsOfService />} />
          <Route path="/tournament/:slug" element={<PublicTournament />} />
          <Route path="/tournament/:slug/live" element={<PublicLiveScoreboard />} />
          <Route path="/consent/confirm" element={<ConsentConfirm />} />
          <Route path="/register/:embedCode" element={<PublicRegistration />} />
          <Route path="/accept-invitation" element={<AcceptInvitation />} />
          <Route path="/tournament-waitlist/respond" element={<WaitlistResponse />} />

          {/* Protected routes */}
          {/* Staff home. `/` redirects here, so this is the first page on
              opening the app (CKU R88). It used to render TeamManagement,
              which is why opening the app landed on Teams; Teams now lives at
              its own route below.

              ⚠️ ParentRedirect must stay wrapped around THIS route — it is what
              bounces a parent-only account to /parent, and `/` lands here. */}
          <Route path="/dashboard" element={
            <ProtectedRoute>
              <ParentRedirect>
                <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                  <StaffDashboard />
                </main>
              </ParentRedirect>
            </ProtectedRoute>
          } />
          <Route path="/teams" element={
            <ProtectedRoute>
              <ParentRedirect>
                {/* Unified team management for all club roles. Coaches see
                    only their teams (server-scoped); club admins see all
                    club teams. Hide create/delete buttons for non-admins. */}
                <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                  <TeamManagement />
                </main>
              </ParentRedirect>
            </ProtectedRoute>
          } />
          <Route path="/calendar" element={<ProtectedRoute><TeamCalendar /></ProtectedRoute>} />
          <Route path="/team/:teamId" element={<TeamDetailPage />} />
          <Route path="/team/:teamId/calendar" element={<ProtectedRoute><TeamCalendarPage /></ProtectedRoute>} />
          <Route path="/teams/:teamId/roster" element={<TeamRosterPage />} />
          <Route path="/teams/:teamId/player-cards" element={<ProtectedRoute><PlayerCards /></ProtectedRoute>} />
          <Route path="/athlete/:athleteId" element={<AthleteProfileEnhanced />} />
          <Route path="/athlete/:athleteId/enhanced" element={<AthleteProfileEnhanced />} />
          {/* Both of these rendered with NO guard at all. The backend predicate
              is the control that matters (`userCanReadAthleteDocs` here,
              `te_is_club_staff` on `action=expiring`), but an unguarded route
              renders the page to anyone who types the URL and then fills it with
              403s. Athlete documents stay on ProtectedRoute — a guardian
              legitimately reaches their own child's — while the club-wide
              expiration dashboard is club-wide staff data and takes the admin
              guard, matching /club-documents. */}
          <Route path="/athlete/:athleteId/documents" element={
            <ProtectedRoute>
              <AthleteDocumentsPage />
            </ProtectedRoute>
          } />
          <Route path="/documents/expiring" element={
            <ProtectedClubAdminRoute>
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <ExpirationDashboard />
              </main>
            </ProtectedClubAdminRoute>
          } />
          <Route path="/athletes" element={
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <AthleteManagement />
            </main>
          } />
          <Route path="/coaches" element={
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <CoachManagement />
            </main>
          } />
          <Route path="/coach/:id" element={
            <ProtectedRoute>
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <CoachProfile />
              </main>
            </ProtectedRoute>
          } />
          <Route path="/invitations" element={
            <ProtectedRoute>
              <Invitations />
            </ProtectedRoute>
          } />
          <Route path="/venues" element={
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <VenueManagement />
            </main>
          } />
          <Route path="/sponsors" element={
            <ProtectedRoute>
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <SponsorsManagement />
              </main>
            </ProtectedRoute>
          } />
          <Route path="/club-profile" element={<ClubProfilePage />} />
          {/* ProtectedRoute is authentication, never authorisation — it checks
              only that someone is signed in. The Document Center is a club-admin
              screen (create / edit / delete / assign), so it takes the admin
              guard the way /crew now does. */}
          <Route path="/club-documents" element={
            <ProtectedClubAdminRoute>
              <ClubDocumentCenter />
            </ProtectedClubAdminRoute>
          } />
          <Route path="/program-management" element={<ProgramManagement />} />
          {/* LeagueSettings route removed - use /club-profile for club settings */}
          <Route path="/profile" element={
            <ProtectedRoute>
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <UserProfile />
              </main>
            </ProtectedRoute>
          } />
          <Route path="/roster" element={
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <TeamManagement />
            </main>
          } />

          {/* Tournament routes */}
          <Route path="/tournaments" element={
            <ProtectedRoute>
              <TournamentList />
            </ProtectedRoute>
          } />
          <Route path="/tournaments/create" element={
            <ProtectedRoute>
              <TournamentCreate />
            </ProtectedRoute>
          } />
          <Route path="/tournaments/:id" element={
            <ProtectedRoute>
              <TournamentDetail />
            </ProtectedRoute>
          } />
          <Route path="/tournaments/:id/edit" element={
            <ProtectedRoute>
              <TournamentCreate />
            </ProtectedRoute>
          } />

          {/* Volunteer Management routes */}
          <Route path="/volunteers" element={
            <ProtectedRoute>
              <VolunteerManagement />
            </ProtectedRoute>
          } />
          <Route path="/volunteers/requests" element={
            <ProtectedRoute>
              <VolunteerSignupRequests />
            </ProtectedRoute>
          } />
          <Route path="/volunteers/compliance" element={
            <ProtectedRoute>
              <ComplianceDashboard />
            </ProtectedRoute>
          } />

          {/* Communication routes */}
          <Route path="/communications" element={
            <ProtectedRoute>
              <CommunicationLog />
            </ProtectedRoute>
          } />
          <Route path="/crew" element={
            <ProtectedClubAdminRoute>
              <CrewRoster />
            </ProtectedClubAdminRoute>
          } />
          <Route path="/email-templates" element={
            <ProtectedRoute>
              <TemplateLibrary />
            </ProtectedRoute>
          } />
          <Route path="/email-templates/new" element={
            <ProtectedRoute>
              <TemplateEditor />
            </ProtectedRoute>
          } />
          <Route path="/email-templates/:id" element={
            <ProtectedRoute>
              <TemplateEditor />
            </ProtectedRoute>
          } />
          <Route path="/email-reporting" element={
            <ProtectedRoute>
              <EmailReporting />
            </ProtectedRoute>
          } />
          <Route path="/imports" element={
            <ProtectedRoute>
              <ImportsIndex />
            </ProtectedRoute>
          } />
          <Route path="/imports/athletes" element={
            <ProtectedRoute>
              <DataImport entity="athletes" />
            </ProtectedRoute>
          } />
          <Route path="/imports/facilities" element={
            <ProtectedRoute>
              <DataImport entity="facilities" />
            </ProtectedRoute>
          } />
          <Route path="/imports/volunteers" element={
            <ProtectedRoute>
              <DataImport entity="volunteers" />
            </ProtectedRoute>
          } />
          <Route path="/imports/coaches" element={
            <ProtectedRoute>
              <DataImport entity="coaches" />
            </ProtectedRoute>
          } />
          <Route path="/imports/teams" element={
            <ProtectedRoute>
              <DataImport entity="teams" />
            </ProtectedRoute>
          } />
          <Route path="/sms-templates" element={
            <ProtectedRoute>
              <SmsTemplates />
            </ProtectedRoute>
          } />
          <Route path="/chat-moderation" element={
            <ProtectedRoute>
              <ChatModeration />
            </ProtectedRoute>
          } />
          <Route path="/communications/broadcast" element={
            <ProtectedRoute>
              <BroadcastCompose />
            </ProtectedRoute>
          } />
          <Route path="/communications/inbox" element={
            <ProtectedRoute>
              <SmsInbox />
            </ProtectedRoute>
          } />

          {/* Help Portal routes */}
          <Route path="/help" element={
            <ProtectedRoute>
              <HelpPortal />
            </ProtectedRoute>
          }>
            <Route path="release-notes" element={<ReleaseNotes />} />
            <Route path="release-notes/:slug" element={<ReleaseNotePage />} />
            <Route path="admin" element={<HelpAdmin />} />
            <Route path=":categorySlug" element={<HelpCategoryPage />} />
            <Route path=":categorySlug/:articleSlug" element={<HelpArticlePage />} />
          </Route>

          {/* Payment routes */}
          <Route path="/payment/revenue" element={
            <ProtectedFinancialRoute requiredPermission="revenue">
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <RevenueDashboard />
              </main>
            </ProtectedFinancialRoute>
          } />
          <Route path="/payment/items" element={
            <ProtectedFinancialRoute requiredPermission="revenue">
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <PaymentItemsList />
              </main>
            </ProtectedFinancialRoute>
          } />
          <Route path="/payment/outstanding" element={
            <ProtectedFinancialRoute requiredPermission="revenue">
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <OutstandingBalances />
              </main>
            </ProtectedFinancialRoute>
          } />
          <Route path="/athlete/:athleteId/payments" element={
            <ProtectedRoute>
              <AthletePaymentsDashboard />
            </ProtectedRoute>
          } />
          <Route path="/payment/checkout/:athleteId/:paymentId?" element={
            <ProtectedRoute>
              <PaymentCheckout />
            </ProtectedRoute>
          } />
          <Route path="/payment/receipt/:transactionId" element={
            <ProtectedRoute>
              <PaymentReceipt />
            </ProtectedRoute>
          } />
          <Route path="/payment/transactions" element={
            <ProtectedFinancialRoute requiredPermission="transactions">
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <TransactionReport />
              </main>
            </ProtectedFinancialRoute>
          } />
          <Route path="/registration/cart" element={
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <RegistrationCart />
            </main>
          } />
          <Route path="/payment/multi-checkout" element={
            <ProtectedRoute>
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <MultiPaymentCheckout />
              </main>
            </ProtectedRoute>
          } />
          <Route path="/payment/roster-fees" element={
            <ProtectedFinancialRoute requiredPermission="roster_fees">
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <RosterFeeStatus />
              </main>
            </ProtectedFinancialRoute>
          } />
          <Route path="/payment/family-invoices" element={
            <ProtectedRoute>
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <FamilyInvoices />
              </main>
            </ProtectedRoute>
          } />

          {/* Demo payment page - public, no auth required */}
          <Route path="/pay/demo" element={<DemoPaymentPage />} />

          {/* Real payment page - public, fetches invoice by ID */}
          <Route path="/pay/:invoiceId" element={<PaymentPage />} />
          {/* Contribution link - public, token-based (Phase 4) */}
          <Route path="/contribute/:token" element={<ContributePage />} />

          {/* Fundraiser Campaign routes - public */}
          <Route path="/donate/:clubSlug/campaign/:campaignSlug" element={<FundraiserCampaign />} />
          <Route path="/donate/thank-you/:donationId" element={<DonationSuccess />} />

          {/* Fundraiser Campaign routes - admin */}
          <Route path="/admin/fundraisers" element={
            <ProtectedFinancialRoute requiredPermission="revenue">
              <FundraiserAdminWrapper>
                {({ clubId, clubSlug }) => (
                  <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <FundraiserCampaignsList clubId={clubId} clubSlug={clubSlug} />
                  </main>
                )}
              </FundraiserAdminWrapper>
            </ProtectedFinancialRoute>
          } />
          <Route path="/admin/fundraisers/new" element={
            <ProtectedFinancialRoute requiredPermission="revenue">
              <FundraiserAdminWrapper>
                {({ clubId, clubSlug, userId }) => (
                  <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <FundraiserCampaignForm clubId={clubId} clubSlug={clubSlug} userId={userId} />
                  </main>
                )}
              </FundraiserAdminWrapper>
            </ProtectedFinancialRoute>
          } />
          <Route path="/admin/fundraisers/:id" element={
            <ProtectedFinancialRoute requiredPermission="revenue">
              <FundraiserAdminWrapper>
                {({ clubSlug, userId }) => (
                  <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <FundraiserCampaignDashboard clubSlug={clubSlug} userId={userId} />
                  </main>
                )}
              </FundraiserAdminWrapper>
            </ProtectedFinancialRoute>
          } />
          <Route path="/admin/fundraisers/:id/edit" element={
            <ProtectedFinancialRoute requiredPermission="revenue">
              <FundraiserAdminWrapper>
                {({ clubId, clubSlug, userId }) => (
                  <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <FundraiserCampaignForm clubId={clubId} clubSlug={clubSlug} userId={userId} />
                  </main>
                )}
              </FundraiserAdminWrapper>
            </ProtectedFinancialRoute>
          } />

          {/* Super Admin route */}
          <Route path="/super-admin" element={
            <ProtectedSuperAdminRoute>
              <SuperAdminDashboard />
            </ProtectedSuperAdminRoute>
          } />

          {/* Parent Portal routes */}
          <Route path="/parent" element={
            <ProtectedParentRoute>
              <ParentPortalLayout />
            </ProtectedParentRoute>
          }>
            <Route index element={<ParentDashboard />} />
            <Route path="athletes" element={<MyAthletesPage />} />
            <Route path="athlete/:id" element={<AthleteDetailPage />} />
            <Route path="payments" element={<PaymentStatusPage />} />
            <Route path="payments/checkout" element={<MakePaymentPage />} />
            <Route path="settings" element={<UserProfile />} />
            <Route path="schedule" element={<UpcomingEventsPage />} />
            <Route path="schedule/rsvp/:id" element={<ScheduleRSVPPage />} />
            <Route path="chat" element={<TeamChatPage />} />
            <Route path="announcements" element={<AnnouncementsPage />} />
            <Route path="announcements/:id" element={<AnnouncementsPage />} />
            <Route path="documents" element={<DocumentsPage />} />
            <Route path="documents/:id" element={<DocumentsPage />} />
            <Route path="medical/:id" element={<MedicalInfoPage />} />
            <Route path="volunteer" element={<VolunteerPage />} />
            <Route path="more" element={<MoreMenuPage />} />
            <Route path="pay/:invoiceId" element={<PaymentPage />} />
            {/* Catch-all: unknown /parent/* paths redirect to the dashboard
                rather than white-screening or leaking. */}
            <Route path="*" element={<Navigate to="/parent" replace />} />
          </Route>
        </Routes>

        {/* Chat Widget - visible when logged in, but not on parent portal (has its own chat) */}
        {user && !isParentPortal && <ChatWidget />}
        {/* Bottom-LEFT so it never sits on the chat launcher, and not on the
            parent portal at all — that surface's bottom strip is the nav +
            sponsor marquee, so its entry point is a row in the More menu. */}
        {user && !isParentPortal && <SupportButton />}
      </div>
  );
}

function App() {
  return (
    <ErrorBoundary>
      <Router>
        <AuthProvider>
          <OrgProvider>
            <ThemeProvider>
              <FinancialPermissionsProvider>
                <RegistrationCartProvider>
                  <AppContent />
                </RegistrationCartProvider>
              </FinancialPermissionsProvider>
            </ThemeProvider>
          </OrgProvider>
        </AuthProvider>
      </Router>
    </ErrorBoundary>
  );
}

export default App;