import React, { useState } from 'react';
import { BrowserRouter as Router, Routes, Route, Link } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import ProtectedRoute from './components/ProtectedRoute';
import Home from './pages/Home';
import Login from './pages/Login';
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
import { useParams } from 'react-router-dom';

// Team Roster Page Component
const TeamRosterPage: React.FC = () => {
  const { teamId } = useParams<{ teamId: string }>();
  const [team, setTeam] = React.useState<{ id: number; name: string } | null>(null);
  const [loading, setLoading] = React.useState(true);

  React.useEffect(() => {
    const fetchTeam = async () => {
      try {
        const response = await fetch(`http://localhost:8889/teams-gateway.php?id=${teamId}`);
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
  const [userRole, setUserRole] = useState<'club_manager' | 'coach'>('club_manager');
  const { user, logout } = useAuth();

  return (
    <div className="min-h-screen bg-white">
        {user && (
          <nav className="bg-white border-b-2 border-forest-800">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              <div className="flex justify-between h-16">
                <div className="flex items-center space-x-8">
                  <Link to="/dashboard" className="text-2xl font-bold text-forest-800 uppercase tracking-wide">TEAMS ELEVATED</Link>
                  <div className="flex space-x-4">
                    {userRole === 'club_manager' ? (
                      <>
                        <Link to="/dashboard" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Teams</Link>
                        <Link to="/athletes" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Athletes</Link>
                        <Link to="/coaches" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Coaches</Link>
                        <Link to="/calendar" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Calendar</Link>
                        <Link to="/documents/expiring" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Documents</Link>
                        <Link to="/venues" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Venues</Link>
                        <Link to="/program-management" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Programs</Link>
                      </>
                    ) : (
                      <>
                        <Link to="/dashboard" className="text-forest-800 hover:text-forest-600 uppercase font-medium">My Teams</Link>
                        <Link to="/athletes" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Athletes</Link>
                        <Link to="/calendar" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Calendar</Link>
                        <Link to="/documents/expiring" className="text-forest-800 hover:text-forest-600 uppercase font-medium">Documents</Link>
                      </>
                    )}
                  </div>
                </div>
                <div className="flex items-center">
                  <div className="relative">
                    <select
                      className="bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 pr-8 uppercase focus:outline-none appearance-none cursor-pointer"
                      value={userRole}
                      onChange={async (e) => {
                        const value = e.target.value;
                        if (value === 'sign_out') {
                          await logout();
                          window.location.href = '/';
                        } else if (value === 'club_profile') {
                          window.location.href = '/club-profile';
                        } else {
                          setUserRole(value as any);
                        }
                      }}
                    >
                      <option value="club_manager">Club Manager</option>
                      {userRole === 'club_manager' && <option value="club_profile" style={{paddingLeft: '20px'}}>⤷ Club Profile</option>}
                      <option value="coach">Coach</option>
                      <option value="sign_out">Sign Out</option>
                    </select>
                    <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-forest-800">
                      <svg className="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                        <path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/>
                      </svg>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </nav>
        )}

        {!user && window.location.pathname === '/' && (
          <nav className="bg-white border-b-2 border-forest-800">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              <div className="flex justify-between h-16">
                <div className="flex items-center">
                  <Link to="/" className="text-2xl font-bold text-forest-800 uppercase tracking-wide">TEAMS ELEVATED</Link>
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
          <Route path="/verify-magic-link" element={<VerifyMagicLink />} />
          <Route path="/register/:embedCode" element={<PublicRegistration />} />

          {/* Protected routes */}
          <Route path="/dashboard" element={
            <ProtectedRoute>
              {userRole === 'club_manager' ? (
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
          <Route path="/coaches" element={<CoachManagement />} />
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
          <Route path="/roster" element={
            userRole === 'coach' ? <CoachDashboard /> :
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
              <TeamManagement />
            </main>
          } />
        </Routes>
      </div>
  );
}

function App() {
  return (
    <Router>
      <AuthProvider>
        <AppContent />
      </AuthProvider>
    </Router>
  );
}

export default App;