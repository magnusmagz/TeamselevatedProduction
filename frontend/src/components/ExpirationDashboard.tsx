import React, { useState, useEffect } from 'react';

interface ExpiringDocument {
  athlete_id: number;
  athlete_name: string;
  document_type: string;
  document_name: string;
  expires_date: string;
  days_until_expiry: number;
}

const ExpirationDashboard: React.FC = () => {
  const [expiringDocs, setExpiringDocs] = useState<ExpiringDocument[]>([]);
  const [loading, setLoading] = useState(true);
  const [daysFilter, setDaysFilter] = useState(30);

  useEffect(() => {
    fetchExpiringDocuments();
  }, [daysFilter]);

  const fetchExpiringDocuments = async () => {
    try {
      const response = await fetch(`http://localhost:3003/api/documents/expiring?days=${daysFilter}`);
      const data = await response.json();
      if (data.success) {
        setExpiringDocs(data.expiring);
      }
    } catch (error) {
      console.error('Failed to fetch expiring documents:', error);
    } finally {
      setLoading(false);
    }
  };

  const getUrgencyColor = (days: number) => {
    if (days <= 7) return 'text-red-600 bg-red-50';
    if (days <= 14) return 'text-orange-600 bg-orange-50';
    if (days <= 30) return 'text-yellow-600 bg-yellow-50';
    return 'text-gray-600 bg-gray-50';
  };

  const groupByUrgency = () => {
    const expired = expiringDocs.filter(d => d.days_until_expiry < 0);
    const thisWeek = expiringDocs.filter(d => d.days_until_expiry >= 0 && d.days_until_expiry <= 7);
    const nextTwoWeeks = expiringDocs.filter(d => d.days_until_expiry > 7 && d.days_until_expiry <= 14);
    const thisMonth = expiringDocs.filter(d => d.days_until_expiry > 14 && d.days_until_expiry <= 30);
    const later = expiringDocs.filter(d => d.days_until_expiry > 30);

    return { expired, thisWeek, nextTwoWeeks, thisMonth, later };
  };

  if (loading) {
    return <div className="p-5">Loading expiration alerts...</div>;
  }

  const groups = groupByUrgency();

  return (
    <div>
      <div className="mb-8">
        <h2 className="text-3xl font-bold text-brand-primary mb-2 uppercase tracking-wide">Document Expiration Dashboard</h2>
        <p className="text-gray-600">Monitor and manage expiring athlete documents</p>
      </div>

      {/* Filter Controls */}
      <div className="mb-6 flex gap-2">
        <button
          onClick={() => setDaysFilter(7)}
          className={`px-4 py-2 border border-brand-secondary rounded-md uppercase font-medium ${
            daysFilter === 7
              ? 'bg-brand-primary text-white'
              : 'bg-white text-brand-primary hover:bg-brand-primary hover:text-white'
          }`}
        >
          Next 7 Days
        </button>
        <button
          onClick={() => setDaysFilter(30)}
          className={`px-4 py-2 border border-brand-secondary rounded-md uppercase font-medium ${
            daysFilter === 30
              ? 'bg-brand-primary text-white'
              : 'bg-white text-brand-primary hover:bg-brand-primary hover:text-white'
          }`}
        >
          Next 30 Days
        </button>
        <button
          onClick={() => setDaysFilter(60)}
          className={`px-4 py-2 border border-brand-secondary rounded-md uppercase font-medium ${
            daysFilter === 60
              ? 'bg-brand-primary text-white'
              : 'bg-white text-brand-primary hover:bg-brand-primary hover:text-white'
          }`}
        >
          Next 60 Days
        </button>
        <button
          onClick={() => setDaysFilter(90)}
          className={`px-4 py-2 border border-brand-secondary rounded-md uppercase font-medium ${
            daysFilter === 90
              ? 'bg-brand-primary text-white'
              : 'bg-white text-brand-primary hover:bg-brand-primary hover:text-white'
          }`}
        >
          Next 90 Days
        </button>
      </div>

      {/* Summary Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        {groups.expired.length > 0 && (
          <div className="p-6 border border-red-200 rounded-md bg-red-50">
            <div className="text-4xl font-bold text-red-600 mb-1">{groups.expired.length}</div>
            <div className="text-sm text-red-800 uppercase tracking-wide">Already Expired</div>
          </div>
        )}
        <div className={`p-6 border rounded-md ${
          groups.thisWeek.length > 0
            ? 'border-orange-200 bg-orange-50'
            : 'border-brand-secondary bg-white'
        }`}>
          <div className={`text-4xl font-bold mb-1 ${
            groups.thisWeek.length > 0 ? 'text-orange-600' : 'text-brand-primary'
          }`}>
            {groups.thisWeek.length}
          </div>
          <div className="text-sm uppercase tracking-wide text-brand-primary">This Week</div>
        </div>
        <div className={`p-6 border rounded-md ${
          groups.nextTwoWeeks.length > 0
            ? 'border-yellow-200 bg-yellow-50'
            : 'border-brand-secondary bg-white'
        }`}>
          <div className={`text-4xl font-bold mb-1 ${
            groups.nextTwoWeeks.length > 0 ? 'text-yellow-600' : 'text-brand-primary'
          }`}>
            {groups.nextTwoWeeks.length}
          </div>
          <div className="text-sm uppercase tracking-wide text-brand-primary">Next 2 Weeks</div>
        </div>
        <div className="p-6 border border-brand-secondary rounded-md bg-white">
          <div className="text-4xl font-bold text-brand-primary mb-1">{groups.thisMonth.length}</div>
          <div className="text-sm uppercase tracking-wide text-brand-primary">This Month</div>
        </div>
      </div>

      {/* Expired Documents Alert */}
      {groups.expired.length > 0 && (
        <div className="mb-6 p-6 bg-red-50 border border-red-200 rounded-md">
          <h3 className="font-bold text-red-800 mb-4 text-lg uppercase tracking-wide">
            ⚠️ EXPIRED DOCUMENTS - Immediate Action Required
          </h3>
          <div className="bg-white border border-red-200 rounded-md">
            <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="bg-red-100 border-b border-red-200">
                  <th className="text-left py-3 px-4 font-bold text-red-800 uppercase text-sm tracking-wide">Athlete</th>
                  <th className="text-left py-3 px-4 font-bold text-red-800 uppercase text-sm tracking-wide">Document</th>
                  <th className="text-left py-3 px-4 font-bold text-red-800 uppercase text-sm tracking-wide">Expired</th>
                </tr>
              </thead>
              <tbody>
                {groups.expired.map((doc, idx) => (
                  <tr key={idx} className="border-b border-red-200 hover:bg-red-50">
                    <td className="py-3 px-4 font-medium">{doc.athlete_name}</td>
                    <td className="py-3 px-4">{doc.document_type}</td>
                    <td className="py-3 px-4 text-red-600 font-bold">
                      {Math.abs(doc.days_until_expiry)} days ago
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
          </div>
        </div>
      )}

      {/* Expiring Soon Table */}
      {expiringDocs.filter(d => d.days_until_expiry >= 0).length > 0 && (
        <div className="border border-brand-secondary rounded-md bg-white">
          <div className="bg-brand-primary p-4 border-b border-brand-secondary">
            <h3 className="font-bold text-white uppercase tracking-wide">Expiring Documents</h3>
          </div>
          <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="bg-gray-50 border-b border-brand-secondary">
                <th className="text-left p-4 font-bold text-brand-primary uppercase text-sm tracking-wide">Days</th>
                <th className="text-left p-4 font-bold text-brand-primary uppercase text-sm tracking-wide">Athlete</th>
                <th className="text-left p-4 font-bold text-brand-primary uppercase text-sm tracking-wide">Document Type</th>
                <th className="text-left p-4 font-bold text-brand-primary uppercase text-sm tracking-wide">Document Name</th>
                <th className="text-left p-4 font-bold text-brand-primary uppercase text-sm tracking-wide">Expiration Date</th>
                <th className="text-left p-4 font-bold text-brand-primary uppercase text-sm tracking-wide">Action</th>
              </tr>
            </thead>
            <tbody>
              {expiringDocs
                .filter(d => d.days_until_expiry >= 0)
                .sort((a, b) => a.days_until_expiry - b.days_until_expiry)
                .map((doc, idx) => (
                  <tr key={idx} className="border-b border-gray-300 hover:bg-gray-50">
                    <td className="p-4">
                      <span className={`px-3 py-1 border rounded-md text-sm font-bold uppercase ${
                        doc.days_until_expiry <= 7
                          ? 'border-red-200 bg-red-50 text-red-600'
                          : doc.days_until_expiry <= 14
                          ? 'border-orange-200 bg-orange-50 text-orange-600'
                          : doc.days_until_expiry <= 30
                          ? 'border-yellow-200 bg-yellow-50 text-yellow-600'
                          : 'border-brand-secondary bg-gray-50 text-brand-primary'
                      }`}>
                        {doc.days_until_expiry}d
                      </span>
                    </td>
                    <td className="p-4 font-medium text-brand-primary">{doc.athlete_name}</td>
                    <td className="p-4 text-brand-primary">{doc.document_type}</td>
                    <td className="p-4 text-sm text-gray-600">{doc.document_name}</td>
                    <td className="p-4 text-brand-primary">{new Date(doc.expires_date).toLocaleDateString()}</td>
                    <td className="p-4">
                      <button
                        onClick={() => window.location.href = `/athlete/${doc.athlete_id}/documents`}
                        className="bg-brand-primary text-white border border-brand-secondary rounded-md px-3 py-1 text-sm uppercase hover:bg-brand-primary"
                      >
                        Update →
                      </button>
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
          </div>
        </div>
      )}

      {/* No Expirations Message */}
      {expiringDocs.length === 0 && (
        <div className="p-12 text-center bg-green-50 border border-green-200 rounded-md">
          <h3 className="font-bold text-green-800 mb-3 text-xl uppercase tracking-wide">All Clear!</h3>
          <p className="text-green-700 text-lg">No documents expiring in the next {daysFilter} days.</p>
        </div>
      )}

      {/* Quick Actions */}
      <div className="mt-6 p-6 bg-gray-50 border border-brand-secondary rounded-md">
        <h4 className="font-bold mb-4 text-brand-primary uppercase tracking-wide">Quick Actions</h4>
        <ul className="space-y-2">
          <li className="flex items-center text-brand-primary">
            <span className="w-2 h-2 bg-brand-primary mr-3"></span>
            Send reminder emails to parents about expiring documents
          </li>
          <li className="flex items-center text-brand-primary">
            <span className="w-2 h-2 bg-brand-primary mr-3"></span>
            Generate compliance report for club requirements
          </li>
          <li className="flex items-center text-brand-primary">
            <span className="w-2 h-2 bg-brand-primary mr-3"></span>
            Bulk update expiration dates for seasonal documents
          </li>
          <li className="flex items-center text-brand-primary">
            <span className="w-2 h-2 bg-brand-primary mr-3"></span>
            Export list of non-compliant athletes
          </li>
        </ul>
      </div>
    </div>
  );
};

export default ExpirationDashboard;