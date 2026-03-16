import React from 'react';
import { BrowserRouter as Router, Routes, Route, Link, useLocation } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { OrgProvider, useOrg } from './contexts/OrgContext';
import { ThemeProvider } from './contexts/ThemeContext';
// LeagueSelector removed - clubs are now the top-level entity
import BrandingLogo from './components/BrandingLogo';
import ProfileMenu from './components/ProfileMenu';
import ProtectedRoute from './components/ProtectedRoute';
import ProtectedFinancialRoute from './components/ProtectedFinancialRoute';
import ProtectedSuperAdminRoute from './components/ProtectedSuperAdminRoute';
import SuperAdminDashboard from './pages/SuperAdminDashboard';
import { ChatWidget } from './components/chat';
import Home from './pages/Home';
import Login from './pages/Login';
import SignUp from './pages/SignUp';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';
import VerifyMagicLink from './pages/VerifyMagicLink';
import GetStarted from './pages/GetStarted';
import PrivacyPolicy from './pages/PrivacyPolicy';
import TermsOfService from './pages/TermsOfService';
import ConsentConfirm from './pages/ConsentConfirm';
import TeamManagement from './components/TeamManagement';
import CoachDashboard from './components/CoachDashboard';
import AthleteProfile from './components/AthleteProfile';
import AthleteProfileEnhanced from './components/AthleteProfileEnhanced';
import AthleteManagement from './components/AthleteManagement';
import CoachManagement from './components/CoachManagement';
// import SeasonsPage from './components/SeasonsPage';  // Replaced with unified ProgramManagement
import UnifiedProgramManagement from './components/ProgramManagement';
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
import { RevenueDashboard } from './pages/RevenueDashboard';
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
import { FundraiserCampaign } from './pages/FundraiserCampaign';
import { DonationSuccess } from './pages/DonationSuccess';
import { FundraiserCampaignsList } from './pages/FundraiserCampaignsList';
import { FundraiserCampaignForm } from './pages/FundraiserCampaignForm';
import { FundraiserCampaignDashboard } from './pages/FundraiserCampaignDashboard';
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

// Team Roster Page Component
const TeamRosterPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { teamId } = useParams<{ teamId: string }>();
  const [team, setTeam] = React.useState<{ id: number; name: string } | null>(null);
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
          setTeam({ id: data.id, name: data.name });
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
  const { isClubAdmin } = useOrg();
  const location = useLocation();
  const [mobileMenuOpen, setMobileMenuOpen] = React.useState(false);

  // Determine if user has admin capabilities (super_admin always gets full admin view)
  const isAdmin = isClubAdmin || user?.system_role === 'super_admin';

  // Hide floating chat widget on parent portal (has its own chat in bottom nav)
  const isParentPortal = location.pathname.startsWith('/parent');

  // Close mobile menu on route change
  React.useEffect(() => {
    setMobileMenuOpen(false);
  }, [location.pathname]);

  const navLinks = isAdmin ? [
    { to: '/payment/revenue', label: 'Revenue' },
    { to: '/dashboard', label: 'Teams' },
    { to: '/athletes', label: 'Athletes' },
    { to: '/coaches', label: 'Coaches' },
    { to: '/calendar', label: 'Calendar' },
    { to: '/venues', label: 'Facilities' },
    { to: '/sponsors', label: 'Sponsors' },
    { to: '/admin/fundraisers', label: 'Fundraisers' },
    { to: '/program-management', label: 'Programs' },
    ...(user?.system_role === 'super_admin' ? [{ to: '/super-admin', label: 'Platform Admin' }] : []),
  ] : [
    { to: '/dashboard', label: 'My Teams' },
    { to: '/athletes', label: 'Athletes' },
    { to: '/calendar', label: 'Calendar' },
    ...(user?.system_role === 'super_admin' ? [{ to: '/super-admin', label: 'Platform Admin' }] : []),
  ];

  return (
    <div className="min-h-screen bg-white">
        <DemoModeBanner />
        {user && !isParentPortal && <InstallPrompt />}
        {user && !isParentPortal && (
          <nav className="bg-white border-b border-brand-secondary">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              {/* Top row: Logo and user controls */}
              <div className="flex justify-between items-center h-20 border-b border-brand-secondary">
                <Link to="/dashboard" className="flex items-center">
                  <BrandingLogo size="xl" fallbackToText={true} />
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
                  <ProfileMenu />
                </div>
              </div>

              {/* Desktop navigation */}
              <div className="hidden md:flex space-x-6 h-12 items-center">
                {navLinks.map((link) => (
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
                ))}
              </div>

              {/* Mobile navigation drawer */}
              {mobileMenuOpen && (
                <div className="md:hidden border-t border-brand-secondary">
                  <div className="py-2 space-y-1">
                    {navLinks.map((link) => (
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
                    ))}
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
          <Route path="/" element={<Home />} />
          <Route path="/get-started" element={<GetStarted />} />
          <Route path="/login" element={<Login />} />
          <Route path="/signup" element={<SignUp />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/reset-password" element={<ResetPassword />} />
          <Route path="/verify-magic-link" element={<VerifyMagicLink />} />
          <Route path="/privacy-policy" element={<PrivacyPolicy />} />
          <Route path="/terms-of-service" element={<TermsOfService />} />
          <Route path="/consent/confirm" element={<ConsentConfirm />} />
          <Route path="/register/:embedCode" element={<PublicRegistration />} />
          <Route path="/accept-invitation" element={<AcceptInvitation />} />

          {/* Protected routes */}
          <Route path="/dashboard" element={
            <ProtectedRoute>
              <ParentRedirect>
                {isAdmin ? (
                  <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <TeamManagement />
                  </main>
                ) : (
                  <CoachDashboard />
                )}
              </ParentRedirect>
            </ProtectedRoute>
          } />
          <Route path="/calendar" element={<ProtectedRoute><TeamCalendar /></ProtectedRoute>} />
          <Route path="/team/:teamId" element={<TeamDetailPage />} />
          <Route path="/team/:teamId/calendar" element={<ProtectedRoute><TeamCalendarPage /></ProtectedRoute>} />
          <Route path="/teams/:teamId/roster" element={<TeamRosterPage />} />
          <Route path="/athlete/:athleteId" element={<AthleteProfile />} />
          <Route path="/athlete/:athleteId/enhanced" element={<AthleteProfileEnhanced />} />
          <Route path="/athlete/:athleteId/documents" element={
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <DocumentManager athleteId={window.location.pathname.split('/')[2]} />
            </main>
          } />
          <Route path="/documents/expiring" element={
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <ExpirationDashboard />
            </main>
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
          <Route path="/seasons" element={
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <UnifiedProgramManagement />
            </main>
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
            isAdmin ?
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <TeamManagement />
            </main> :
            <CoachDashboard />
          } />

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
            <Route path="schedule" element={<UpcomingEventsPage />} />
            <Route path="schedule/rsvp/:id" element={<ScheduleRSVPPage />} />
            <Route path="chat" element={<TeamChatPage />} />
            <Route path="announcements" element={<AnnouncementsPage />} />
            <Route path="documents" element={<DocumentsPage />} />
            <Route path="documents/:id" element={<DocumentsPage />} />
            <Route path="medical/:id" element={<MedicalInfoPage />} />
            <Route path="more" element={<MoreMenuPage />} />
          </Route>
        </Routes>

        {/* Chat Widget - visible when logged in, but not on parent portal (has its own chat) */}
        {user && !isParentPortal && <ChatWidget />}
      </div>
  );
}

function App() {
  return (
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
  );
}

export default App;