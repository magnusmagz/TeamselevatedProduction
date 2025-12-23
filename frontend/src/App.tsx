import React from 'react';
import { BrowserRouter as Router, Routes, Route, Link } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { OrgProvider, useOrg } from './contexts/OrgContext';
import LeagueSelector from './components/LeagueSelector';
import BrandingLogo from './components/BrandingLogo';
import ProfileMenu from './components/ProfileMenu';
import ProtectedRoute from './components/ProtectedRoute';
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
import LeagueSettings from './pages/LeagueSettings';
import UserProfile from './pages/UserProfile';
import CoachProfile from './pages/CoachProfile';
import { useParams } from 'react-router-dom';
// Payment components
import { DemoModeBanner } from './components/DemoModeBanner';
import { RevenueDashboard } from './pages/RevenueDashboard';
import { PaymentItemsList } from './pages/PaymentItemsList';
import { AthletePaymentsDashboard } from './pages/AthletePaymentsDashboard';
import { PaymentCheckout } from './pages/PaymentCheckout';

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
        <div className="text-center text-forest-800 py-12">Loading team...</div>
      </main>
    );
  }

  if (!team) {
    return (
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="text-center text-forest-800 py-12">Team not found</div>
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
  const { user, logout } = useAuth();
  const { activeContext, isLeagueAdmin, isClubAdmin } = useOrg();

  // Determine if user has admin capabilities (league or club admin)
  const isAdmin = isLeagueAdmin || isClubAdmin;

  return (
    <div className="min-h-screen bg-white">
        <DemoModeBanner />
        {user && (
          <nav className="bg-white border-b border-forest-200">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              {/* Top row: Logo and user controls */}
              <div className="flex justify-between items-center h-20 border-b border-forest-100">
                <Link to="/dashboard" className="flex items-center">
                  <BrandingLogo size="xl" fallbackToText={true} />
                </Link>
                <div className="flex items-center space-x-4">
                  <LeagueSelector />
                  <ProfileMenu />
                </div>
              </div>

              {/* Bottom row: Navigation menu */}
              <div className="flex space-x-6 h-12 items-center">
                {isAdmin ? (
                  <>
                    <Link to="/dashboard" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Teams</Link>
                    <Link to="/athletes" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Athletes</Link>
                    <Link to="/coaches" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Coaches</Link>
                    <Link to="/calendar" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Calendar</Link>
                    <Link to="/documents/expiring" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Documents</Link>
                    <Link to="/venues" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Venues</Link>
                    <Link to="/program-management" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Programs</Link>
                  </>
                ) : (
                  <>
                    <Link to="/dashboard" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">My Teams</Link>
                    <Link to="/athletes" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Athletes</Link>
                    <Link to="/calendar" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Calendar</Link>
                    <Link to="/documents/expiring" className="text-forest-800 hover:text-forest-600 uppercase font-medium text-sm">Documents</Link>
                  </>
                )}
              </div>
            </div>
          </nav>
        )}

        {!user && window.location.pathname === '/' && (
          <nav className="bg-white border-b border-forest-200">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              <div className="flex justify-between h-16">
                <div className="flex items-center">
                  <Link to="/" className="flex items-center">
                    <span className="text-lg font-bold text-forest-800 uppercase tracking-wide">TEAMS ELEVATED</span>
                  </Link>
                </div>
                <div className="flex items-center">
                  <Link
                    to="/login"
                    className="text-forest-800 hover:text-forest-600 uppercase font-semibold"
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
          <Route path="/league-settings" element={
            <ProtectedRoute>
              <LeagueSettings />
            </ProtectedRoute>
          } />
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
            <ProtectedRoute>
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <RevenueDashboard />
              </main>
            </ProtectedRoute>
          } />
          <Route path="/payment/items" element={
            <ProtectedRoute>
              <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <PaymentItemsList />
              </main>
            </ProtectedRoute>
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
        </Routes>
      </div>
  );
}

function App() {
  return (
    <Router>
      <AuthProvider>
        <OrgProvider>
          <AppContent />
        </OrgProvider>
      </AuthProvider>
    </Router>
  );
}

export default App;