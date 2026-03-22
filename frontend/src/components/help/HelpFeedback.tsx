import React, { useState } from 'react';
import { submitFeedback } from '../../services/helpApi';

interface Props {
  articleId: number;
  initialFeedback?: boolean | null;
}

const HelpFeedback: React.FC<Props> = ({ articleId, initialFeedback }) => {
  const [feedback, setFeedback] = useState<boolean | null>(initialFeedback ?? null);
  const [submitted, setSubmitted] = useState(initialFeedback !== null && initialFeedback !== undefined);

  const handleFeedback = async (isHelpful: boolean) => {
    try {
      await submitFeedback(articleId, isHelpful);
      setFeedback(isHelpful);
      setSubmitted(true);
    } catch {
      // silently fail
    }
  };

  return (
    <div className="border-t border-gray-200 pt-6 mt-8">
      {submitted ? (
        <p className="text-sm text-gray-500">
          Thanks for your feedback!
        </p>
      ) : (
        <div className="flex items-center gap-3">
          <span className="text-sm text-gray-600">Was this article helpful?</span>
          <button
            onClick={() => handleFeedback(true)}
            className={`px-3 py-1.5 text-sm rounded-md border transition-colors ${
              feedback === true
                ? 'bg-green-50 border-green-300 text-green-700'
                : 'border-gray-300 text-gray-600 hover:bg-gray-50'
            }`}
          >
            <svg className="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5" />
            </svg>
            Yes
          </button>
          <button
            onClick={() => handleFeedback(false)}
            className={`px-3 py-1.5 text-sm rounded-md border transition-colors ${
              feedback === false
                ? 'bg-red-50 border-red-300 text-red-700'
                : 'border-gray-300 text-gray-600 hover:bg-gray-50'
            }`}
          >
            <svg className="w-4 h-4 inline mr-1 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5" />
            </svg>
            No
          </button>
        </div>
      )}
    </div>
  );
};

export default HelpFeedback;
