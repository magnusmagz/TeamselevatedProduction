import React, { useState, useEffect } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';

interface PaymentItem {
  id: number;
  name: string;
  description: string;
  item_type: string;
  base_price: string;
  accounting_code: string | null;
  program_name: string;
  is_recurring: boolean;
  is_required: boolean;
  allow_payment_plan: boolean;
  active: boolean;
}

interface Program {
  id: number;
  name: string;
  season: string;
}

/**
 * Admin: Payment Items List
 * Shows all payment items with program/season filtering
 */
export const PaymentItemsList: React.FC = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const [items, setItems] = useState<PaymentItem[]>([]);
  const [programs, setPrograms] = useState<Program[]>([]);
  const [seasons, setSeasons] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [programName, setProgramName] = useState<string>('');

  // Get filters from URL query params
  const selectedProgramId = searchParams.get('program_id') || '';
  const selectedSeason = searchParams.get('season') || '';

  // Fetch available programs and seasons
  useEffect(() => {
    fetch(`${process.env.REACT_APP_API_URL}/api/programs.php?club_id=13`)
      .then(res => res.json())
      .then(data => {
        if (data.success && data.programs) {
          setPrograms(data.programs);
          // Extract unique seasons
          const seasonSet = new Set<string>();
          data.programs.forEach((p: Program) => {
            if (p.season) seasonSet.add(p.season);
          });
          setSeasons(Array.from(seasonSet));
        }
      })
      .catch(err => console.error('Error fetching programs:', err));
  }, []);

  // Fetch payment items based on filters
  useEffect(() => {
    setLoading(true);

    let url = `${process.env.REACT_APP_API_URL}/api/payment-items.php?club_id=13`;
    if (selectedProgramId) {
      url += `&program_id=${selectedProgramId}`;
    }
    if (selectedSeason) {
      url += `&season=${encodeURIComponent(selectedSeason)}`;
    }

    fetch(url)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setItems(data.items);
          if (selectedProgramId && data.items.length > 0) {
            setProgramName(data.items[0].program_name);
          } else {
            setProgramName('');
          }
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching payment items:', err);
        setLoading(false);
      });
  }, [selectedProgramId, selectedSeason]);

  const formatPrice = (price: string) => {
    return `$${parseFloat(price).toFixed(2)}`;
  };

  const getTypeColor = (type: string) => {
    const colors: Record<string, string> = {
      registration: 'bg-blue-100 text-blue-800',
      dues: 'bg-green-100 text-green-800',
      uniform: 'bg-purple-100 text-purple-800',
      tournament: 'bg-orange-100 text-orange-800',
      merchandise: 'bg-pink-100 text-pink-800',
    };
    return colors[type] || 'bg-gray-100 text-gray-800';
  };

  const handleProgramChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const newParams = new URLSearchParams(searchParams);
    if (e.target.value) {
      newParams.set('program_id', e.target.value);
    } else {
      newParams.delete('program_id');
    }
    setSearchParams(newParams);
  };

  const handleSeasonChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const newParams = new URLSearchParams(searchParams);
    if (e.target.value) {
      newParams.set('season', e.target.value);
      newParams.delete('program_id'); // Clear program when changing season
    } else {
      newParams.delete('season');
    }
    setSearchParams(newParams);
  };

  const clearFilters = () => {
    setSearchParams({});
  };

  // Filter programs by selected season
  const filteredPrograms = selectedSeason
    ? programs.filter(p => p.season === selectedSeason)
    : programs;

  return (
    <div className="container mx-auto p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-3xl font-bold">Payment Items</h1>
        <button
          onClick={() => navigate('/payment/revenue')}
          className="text-brand-primary hover:text-brand-primary-dark"
        >
          &larr; Back to Revenue Dashboard
        </button>
      </div>

      {/* Filters */}
      <div className="bg-white shadow rounded-lg p-4 mb-6">
        <div className="flex flex-wrap gap-4 items-end">
          {/* Season Filter */}
          <div className="flex-1 min-w-[200px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Season</label>
            <select
              value={selectedSeason}
              onChange={handleSeasonChange}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Seasons</option>
              {seasons.map(season => (
                <option key={season} value={season}>{season}</option>
              ))}
            </select>
          </div>

          {/* Program Filter */}
          <div className="flex-1 min-w-[200px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Program</label>
            <select
              value={selectedProgramId}
              onChange={handleProgramChange}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Programs</option>
              {filteredPrograms.map(program => (
                <option key={program.id} value={program.id}>{program.name}</option>
              ))}
            </select>
          </div>

          {/* Clear Filters Button */}
          {(selectedProgramId || selectedSeason) && (
            <button
              onClick={clearFilters}
              className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded"
            >
              Clear Filters
            </button>
          )}
        </div>
      </div>

      {/* Active Filters Display */}
      {(selectedProgramId || selectedSeason) && (
        <div className="mb-4 flex items-center gap-2 text-sm text-gray-600">
          <span>Showing:</span>
          {selectedSeason && (
            <span className="px-2 py-1 bg-blue-100 text-blue-800 rounded-full">
              {selectedSeason}
            </span>
          )}
          {programName && (
            <span className="px-2 py-1 bg-green-100 text-green-800 rounded-full">
              {programName}
            </span>
          )}
          <span className="text-gray-400">({items.length} items)</span>
        </div>
      )}

      {/* Payment Items Table */}
      {loading ? (
        <div className="text-center py-10">
          <p className="text-gray-500">Loading payment items...</p>
        </div>
      ) : items.length === 0 ? (
        <div className="bg-white shadow rounded-lg p-10 text-center">
          <p className="text-gray-500">No payment items found.</p>
          {(selectedProgramId || selectedSeason) && (
            <button
              onClick={clearFilters}
              className="mt-4 text-brand-primary hover:text-brand-primary-dark"
            >
              Clear filters to see all items
            </button>
          )}
        </div>
      ) : (
        <div className="bg-white shadow rounded-lg overflow-hidden">
          <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Item Name
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Program
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Type
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Accounting Code
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Price
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Details
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Status
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {items.map(item => (
                <tr key={item.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm font-medium text-gray-900">{item.name}</div>
                    <div className="text-sm text-gray-500">{item.description}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    {item.program_name}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${getTypeColor(item.item_type)}`}>
                      {item.item_type}
                    </span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono">
                    {item.accounting_code || '—'}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {formatPrice(item.base_price)}
                    {item.is_recurring && <span className="text-gray-500 text-xs ml-1">/period</span>}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    <div className="space-y-1">
                      {item.is_required && <div className="text-xs">Required</div>}
                      {item.allow_payment_plan && <div className="text-xs">Payment plan available</div>}
                      {item.is_recurring && <div className="text-xs">Recurring</div>}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${
                      item.active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                    }`}>
                      {item.active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        </div>
      )}
    </div>
  );
};
