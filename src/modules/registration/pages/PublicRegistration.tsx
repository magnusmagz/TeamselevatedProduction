import React from 'react';
import { useParams } from 'react-router-dom';
import PublicRegistrationForm from '../components/PublicRegistrationForm';

const PublicRegistration: React.FC = () => {
  const { embedCode } = useParams<{ embedCode: string }>();

  if (!embedCode) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="text-red-600">Invalid registration link</div>
      </div>
    );
  }

  return <PublicRegistrationForm embedCode={embedCode} />;
};

export default PublicRegistration;