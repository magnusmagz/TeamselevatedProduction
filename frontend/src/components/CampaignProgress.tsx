import React from 'react';

interface CampaignProgressProps {
  amountRaised: number;
  goalAmount: number;
  donorCount: number;
  daysRemaining: number;
  showProgress?: boolean;
}

/**
 * CampaignProgress - Progress bar and stats for fundraiser campaigns
 */
export const CampaignProgress: React.FC<CampaignProgressProps> = ({
  amountRaised,
  goalAmount,
  donorCount,
  daysRemaining,
  showProgress = true
}) => {
  const progressPercent = goalAmount > 0 ? Math.min(100, (amountRaised / goalAmount) * 100) : 0;

  const formatCurrency = (amount: number) => {
    return `$${amount.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
  };

  if (!showProgress) {
    return null;
  }

  return (
    <div className="bg-white rounded-lg p-6 shadow-sm border border-gray-100">
      {/* Amount raised */}
      <div className="mb-4">
        <div className="flex items-baseline gap-2">
          <span className="text-3xl font-bold text-brand-primary">{formatCurrency(amountRaised)}</span>
          <span className="text-gray-500">raised of {formatCurrency(goalAmount)} goal</span>
        </div>
      </div>

      {/* Progress bar */}
      <div className="mb-4">
        <div className="h-4 bg-gray-200 rounded-full overflow-hidden">
          <div
            className="h-full bg-brand-primary rounded-full transition-all duration-500 ease-out"
            style={{ width: `${progressPercent}%` }}
          />
        </div>
        <div className="flex justify-between mt-1 text-sm text-gray-500">
          <span>{progressPercent.toFixed(0)}% funded</span>
          {progressPercent >= 100 && (
            <span className="text-green-600 font-medium">Goal reached!</span>
          )}
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 gap-4 pt-4 border-t border-gray-100">
        <div>
          <p className="text-2xl font-bold text-gray-900">{donorCount}</p>
          <p className="text-sm text-gray-500">{donorCount === 1 ? 'Donor' : 'Donors'}</p>
        </div>
        <div>
          <p className="text-2xl font-bold text-gray-900">
            {daysRemaining <= 0 ? 'Ended' : daysRemaining}
          </p>
          <p className="text-sm text-gray-500">
            {daysRemaining <= 0 ? '' : daysRemaining === 1 ? 'Day left' : 'Days left'}
          </p>
        </div>
      </div>
    </div>
  );
};

export default CampaignProgress;
