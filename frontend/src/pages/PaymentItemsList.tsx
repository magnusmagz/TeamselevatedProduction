import React, { useState, useEffect } from 'react';

interface PaymentItem {
  id: number;
  name: string;
  description: string;
  item_type: string;
  base_price: string;
  program_name: string;
  is_recurring: boolean;
  is_required: boolean;
  allow_payment_plan: boolean;
  active: boolean;
}

/**
 * Admin: Payment Items List
 * Shows all payment items for selected program
 */
export const PaymentItemsList: React.FC = () => {
  const [items, setItems] = useState<PaymentItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [programs, setPrograms] = useState<any[]>([]);
  const [selectedProgram, setSelectedProgram] = useState<string>('');

  // Fetch programs on mount
  useEffect(() => {
    fetch(`${process.env.REACT_APP_API_URL}/api/organization-gateway.php?entity=programs&action=list`)
      .then(res => res.json())
      .then(data => {
        if (data.success && data.programs) {
          setPrograms(data.programs);
          if (data.programs.length > 0) {
            setSelectedProgram(data.programs[0].id);
          }
        }
      })
      .catch(err => console.error('Error fetching programs:', err));
  }, []);

  // Fetch payment items when program changes
  useEffect(() => {
    if (!selectedProgram) return;

    setLoading(true);
    fetch(`${process.env.REACT_APP_API_URL}/api/payment-items.php?program_id=${selectedProgram}`)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          setItems(data.items);
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching payment items:', err);
        setLoading(false);
      });
  }, [selectedProgram]);

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

  return (
    <div className="container mx-auto p-6">
      <h1 className="text-3xl font-bold mb-6">Payment Items</h1>

      {/* Program Filter */}
      <div className="mb-6">
        <label className="block text-sm font-medium mb-2">Select Program:</label>
        <select
          value={selectedProgram}
          onChange={(e) => setSelectedProgram(e.target.value)}
          className="border border-gray-300 rounded px-4 py-2 w-full max-w-md"
        >
          {programs.map(program => (
            <option key={program.id} value={program.id}>
              {program.name}
            </option>
          ))}
        </select>
      </div>

      {/* Payment Items Table */}
      {loading ? (
        <div className="text-center py-10">
          <p className="text-gray-500">Loading payment items...</p>
        </div>
      ) : items.length === 0 ? (
        <div className="text-center py-10">
          <p className="text-gray-500">No payment items found for this program.</p>
        </div>
      ) : (
        <div className="bg-white shadow rounded-lg overflow-hidden">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Item Name
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Type
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
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${getTypeColor(item.item_type)}`}>
                      {item.item_type}
                    </span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {formatPrice(item.base_price)}
                    {item.is_recurring && <span className="text-gray-500 text-xs ml-1">/period</span>}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    <div className="space-y-1">
                      {item.is_required && <div className="text-xs">✓ Required</div>}
                      {item.allow_payment_plan && <div className="text-xs">✓ Payment plan available</div>}
                      {item.is_recurring && <div className="text-xs">✓ Recurring</div>}
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
      )}
    </div>
  );
};
