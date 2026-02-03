import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import PublicRegistrationForm from '../components/PublicRegistrationForm';
import PublicTryoutRegistration from './PublicTryoutRegistration';

const PublicRegistration: React.FC = () => {
  const { embedCode } = useParams<{ embedCode: string }>();
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const [programType, setProgramType] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const checkProgramType = async () => {
      if (!embedCode) {
        setLoading(false);
        return;
      }

      try {
        const response = await fetch(
          `${API_URL}/registration/programs-api.php?path=by-embed&code=${embedCode}`
        );
        const data = await response.json();
        if (data && data.type) {
          setProgramType(data.type);
        }
      } catch (error) {
        console.error('Error fetching program type:', error);
      } finally {
        setLoading(false);
      }
    };

    checkProgramType();
  }, [embedCode, API_URL]);

  if (!embedCode) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="text-red-600">Invalid registration link</div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-brand-primary"></div>
      </div>
    );
  }

  // Use dedicated tryout registration page for tryouts
  if (programType === 'tryout') {
    return <PublicTryoutRegistration />;
  }

  // Use standard form for other program types
  return <PublicRegistrationForm embedCode={embedCode} />;
};

export default PublicRegistration;