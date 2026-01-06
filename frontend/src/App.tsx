import React from 'react';
import { BrowserRouter as Router, Routes, Route, Link } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { OrgProvider, useOrg } from './contexts/OrgContext';
import { ThemeProvider } from './contexts/ThemeContext';
// LeagueSelector removed - clubs are now the top-level entity
import BrandingLogo from './components/BrandingLogo';
import ProfileMenu from './components/ProfileMenu';
import ProtectedRoute from './components/ProtectedRoute';
import ProtectedFinancialRoute from './components/ProtectedFinancialRoute';
import { ChatWidget } from './components/chat';
import Home from './pages/Home';
import Login from './pages/Login';
import SignUp from './pages/SignUp';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';
import VerifyMagicLink from './pages/VerifyMagicLink';
import GetStarted from './pages/GetStarted';
import TeamManagement from './components/TeamManagement';
import CoachDashboard from './components/CoachDashboard';
import AthleteProfile from './components/AthleteProfile';
import AthleteProfileEnhanced from './components/AthleteProfileEnhanced';
import AthleteManagement from './components/AthleteManagement';
import CoachManagement from './components/CoachManagement';
// import SeasonsPage from './components/SeasonsPage';  // Replaced with unified ProgramManagement
import UnifiedProgramManagement from './components/ProgramManagement';
import VenueManagement from './components/VenueManagement';
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
import { RegistrationCartProvider } from './contexts/RegistrationCartContext';

// Team Roster Page Component
const TeamRosterPage: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { teamId } = useParams<{ teamId: string }>();
  const [team, setTeam] = React.useState<{ id: number; name: string } | null>(null);
  const [loading, setLoading] = React.useState(true);

  React.useEffect(() => {
    const fetchTeam = async () => {
      try {
        const response = await fetch(`${API_URL}/legacy/teams-gateway.php?id=${teamId}`);
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

  // Determine if user has admin capabilities
  const isAdmin = isClubAdmin;

  return (
    <div className="min-h-screen bg-white">
        <DemoModeBanner />
        {user && (
          <nav className="bg-white border-b border-brand-secondary">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              {/* Top row: Logo and user controls */}
              <div className="flex justify-between items-center h-20 border-b border-brand-secondary">
                <Link to="/dashboard" className="flex items-center">
                  <BrandingLogo size="xl" fallbackToText={true} />
                </Link>
                <div className="flex items-center space-x-4">
                  <ProfileMenu />
                </div>
              </div>

              {/* Bottom row: Navigation menu */}
              <div className="flex space-x-6 h-12 items-center">
                {isAdmin ? (
                  <>
                    <Link to="/payment/revenue" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Revenue</Link>
                    <Link to="/dashboard" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Teams</Link>
                    <Link to="/athletes" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Athletes</Link>
                    <Link to="/coaches" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Coaches</Link>
                    <Link to="/calendar" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Calendar</Link>
                    <Link to="/documents/expiring" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Documents</Link>
                    <Link to="/venues" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Facilities</Link>
                    <Link to="/program-management" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Programs</Link>
                  </>
                ) : (
                  <>
                    <Link to="/dashboard" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">My Teams</Link>
                    <Link to="/athletes" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Athletes</Link>
                    <Link to="/calendar" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Calendar</Link>
                    <Link to="/documents/expiring" className="text-brand-primary hover:text-brand-primary-hover uppercase font-medium text-sm">Documents</Link>
                  </>
                )}
              </div>
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
          <Route path="/register/:embedCode" element={<PublicRegistration />} />
          <Route path="/accept-invitation" element={<AcceptInvitation />} />

          {/* Protected routes */}
          <Route path="/dashboard" element={
            <ProtectedRoute>
              {isAdmin ? (
                <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                  <TeamManagement />
                </main>
              ) : (
                <CoachDashboard />
              )}
            </ProtectedRoute>
          } />
          <Route path="/calendar" element={<ProtectedRoute><TeamCalendar /></ProtectedRoute>} />
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
        </Routes>

        {/* Chat Widget - only visible when logged in */}
        {user && <ChatWidget />}
      </div>
  );
}

function App() {
  return (
    <Router>
      <AuthProvider>
        <OrgProvider>
          <ThemeProvider>
            <RegistrationCartProvider>
              <AppContent />
            </RegistrationCartProvider>
          </ThemeProvider>
        </OrgProvider>
      </AuthProvider>
    </Router>
  );
}

export default App;