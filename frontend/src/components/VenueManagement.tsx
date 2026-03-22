import React, { useState, useEffect } from 'react';
import GooglePlacesAutocomplete from './GooglePlacesAutocomplete';
// VenueManagement component with address search

interface Field {
  name: string;
  field_type: string;
  surface_type: string;
  dimensions: string;
  has_lights: boolean;
  status: 'available' | 'maintenance' | 'closed';
}

interface Venue {
  id?: number;
  name: string;
  address: string;
  city: string;
  state: string;
  zip: string;
  map_url?: string;
  website?: string;
  field_count?: number;
  fields?: Field[];
  maintenance_contact_name?: string;
  maintenance_contact_phone?: string;
  maintenance_contact_email?: string;
  emergency_contact_name?: string;
  emergency_contact_phone?: string;
  emergency_contact_email?: string;
  billing_contact_name?: string;
  billing_contact_phone?: string;
  billing_contact_email?: string;
  gm_contact_name?: string;
  gm_contact_phone?: string;
  gm_contact_email?: string;
  venue_type?: string;
  parking_type?: string;
  parking_paid?: boolean;
  parking_notes?: string;
  has_lights?: boolean;
  lights_notes?: string;
  is_accessible?: boolean;
  accessibility_notes?: string;
  has_bathrooms?: boolean;
  bathroom_count?: number;
  has_concessions?: boolean;
  concessions_notes?: string;
  seating_type?: string;
  seating_capacity?: number;
  entry_cost?: string;
  entry_cost_amount?: number;
  payment_methods?: string;
  venue_photos?: any[];
}

interface VenueManagementProps {
  onClose?: () => void;
}

const VenueManagement: React.FC<VenueManagementProps> = ({ onClose }) => {
  const API_URL = process.env.REACT_APP_API_URL || 'https://teamselevated-backend-0485388bd66e.herokuapp.com';
  const GOOGLE_MAPS_API_KEY = process.env.REACT_APP_GOOGLE_MAPS_API_KEY || '';
  const [venues, setVenues] = useState<Venue[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [selectedVenue, setSelectedVenue] = useState<Venue | null>(null);
  const [loading, setLoading] = useState(true);
  const [addressSearch, setAddressSearch] = useState('');
  const [searchingAddress, setSearchingAddress] = useState(false);

  const emptyFacilityDetails = {
    venue_type: '',
    parking_type: '',
    parking_paid: false,
    parking_notes: '',
    has_lights: false,
    lights_notes: '',
    is_accessible: false,
    accessibility_notes: '',
    has_bathrooms: false,
    bathroom_count: undefined as number | undefined,
    has_concessions: false,
    concessions_notes: '',
    seating_type: '',
    seating_capacity: undefined as number | undefined,
    entry_cost: '',
    entry_cost_amount: undefined as number | undefined,
    payment_methods: '',
    venue_photos: [] as any[],
  };

  const [formData, setFormData] = useState<Venue>({
    name: '',
    address: '',
    city: '',
    state: '',
    zip: '',
    map_url: '',
    website: '',
    fields: [],
    maintenance_contact_name: '',
    maintenance_contact_phone: '',
    maintenance_contact_email: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    emergency_contact_email: '',
    billing_contact_name: '',
    billing_contact_phone: '',
    billing_contact_email: '',
    gm_contact_name: '',
    gm_contact_phone: '',
    gm_contact_email: '',
    ...emptyFacilityDetails,
  });

  const [newField, setNewField] = useState<Field>({
    name: '',
    field_type: 'Soccer',
    surface_type: 'Grass',
    dimensions: '',
    has_lights: false,
    status: 'available'
  });

  useEffect(() => {
    fetchVenues();
  }, []);

  const fetchVenues = async () => {
    try {
      const response = await fetch(`${API_URL}/legacy/venues-gateway.php`);
      const data = await response.json();
      setVenues(data);
    } catch (error) {
      console.error('Error fetching venues:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleAddVenue = () => {
    setSelectedVenue(null);
    setFormData({
      name: '',
      address: '',
      city: '',
      state: '',
      zip: '',
      map_url: '',
      website: '',
      fields: [],
      maintenance_contact_name: '',
      maintenance_contact_phone: '',
      maintenance_contact_email: '',
      emergency_contact_name: '',
      emergency_contact_phone: '',
      emergency_contact_email: '',
      billing_contact_name: '',
      billing_contact_phone: '',
      billing_contact_email: '',
      gm_contact_name: '',
      gm_contact_phone: '',
      gm_contact_email: '',
      ...emptyFacilityDetails,
    });
    setShowForm(true);
  };

  const handleEditVenue = async (venue: Venue) => {
    try {
      const response = await fetch(`${API_URL}/legacy/venues-gateway.php?id=${venue.id}`);
      const data = await response.json();
      setSelectedVenue(data);
      setFormData(data);
      setShowForm(true);
    } catch (error) {
      console.error('Error fetching venue details:', error);
    }
  };

  const handleDeleteVenue = async (venueId: number) => {
    if (!window.confirm('Are you sure you want to delete this facility? This will also delete all associated fields.')) {
      return;
    }

    try {
      const response = await fetch(`${API_URL}/legacy/venues-gateway.php?id=${venueId}`, {
        method: 'DELETE'
      });

      if (response.ok) {
        fetchVenues();
      }
    } catch (error) {
      console.error('Error deleting venue:', error);
    }
  };

  const handleAddField = () => {
    if (newField.name.trim()) {
      setFormData({
        ...formData,
        fields: [...(formData.fields || []), { ...newField }]
      });
      setNewField({
        name: '',
        field_type: 'Soccer',
        surface_type: 'Grass',
        dimensions: '',
        has_lights: false,
        status: 'available'
      });
    }
  };

  const handleRemoveField = (index: number) => {
    const updatedFields = formData.fields?.filter((_, i) => i !== index) || [];
    setFormData({ ...formData, fields: updatedFields });
  };

  const handleAddressSearch = async () => {
    if (!addressSearch.trim()) return;

    setSearchingAddress(true);
    try {
      const response = await fetch(
        `https://maps.googleapis.com/maps/api/geocode/json?address=${encodeURIComponent(addressSearch)}&key=${GOOGLE_MAPS_API_KEY}`
      );
      const data = await response.json();

      if (data.status === 'OK' && data.results.length > 0) {
        const result = data.results[0];
        const components = result.address_components;

        let streetNumber = '';
        let route = '';
        let city = '';
        let state = '';
        let zip = '';

        components.forEach((component: any) => {
          if (component.types.includes('street_number')) {
            streetNumber = component.long_name;
          }
          if (component.types.includes('route')) {
            route = component.long_name;
          }
          if (component.types.includes('locality')) {
            city = component.long_name;
          }
          if (component.types.includes('administrative_area_level_1')) {
            state = component.short_name;
          }
          if (component.types.includes('postal_code')) {
            zip = component.long_name;
          }
        });

        setFormData({
          ...formData,
          address: `${streetNumber} ${route}`.trim(),
          city,
          state,
          zip
        });
        setAddressSearch(''); // Clear search after success
      } else {
        alert('Address not found. Please try a different search or enter manually.');
      }
    } catch (error) {
      console.error('Error searching address:', error);
      alert('Error searching address. Please enter manually.');
    } finally {
      setSearchingAddress(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      const url = selectedVenue
        ? `${API_URL}/legacy/venues-gateway.php?id=${selectedVenue.id}`
        : `${API_URL}/legacy/venues-gateway.php`;

      // Clean up the data to send - remove undefined/empty fields array
      const dataToSend = {
        name: formData.name,
        address: formData.address,
        city: formData.city,
        state: formData.state,
        zip_code: formData.zip,
        map_url: formData.map_url || null,
        website: formData.website || null,
        fields: formData.fields && formData.fields.length > 0 ? formData.fields : [],
        maintenance_contact_name: formData.maintenance_contact_name || null,
        maintenance_contact_phone: formData.maintenance_contact_phone || null,
        maintenance_contact_email: formData.maintenance_contact_email || null,
        emergency_contact_name: formData.emergency_contact_name || null,
        emergency_contact_phone: formData.emergency_contact_phone || null,
        emergency_contact_email: formData.emergency_contact_email || null,
        billing_contact_name: formData.billing_contact_name || null,
        billing_contact_phone: formData.billing_contact_phone || null,
        billing_contact_email: formData.billing_contact_email || null,
        gm_contact_name: formData.gm_contact_name || null,
        gm_contact_phone: formData.gm_contact_phone || null,
        gm_contact_email: formData.gm_contact_email || null,
        venue_type: formData.venue_type || null,
        parking_type: formData.parking_type || null,
        parking_paid: formData.parking_paid || false,
        parking_notes: formData.parking_notes || null,
        has_lights: formData.has_lights || false,
        lights_notes: formData.lights_notes || null,
        is_accessible: formData.is_accessible || false,
        accessibility_notes: formData.accessibility_notes || null,
        has_bathrooms: formData.has_bathrooms || false,
        bathroom_count: formData.bathroom_count || null,
        has_concessions: formData.has_concessions || false,
        concessions_notes: formData.concessions_notes || null,
        seating_type: formData.seating_type || null,
        seating_capacity: formData.seating_capacity || null,
        entry_cost: formData.entry_cost || null,
        entry_cost_amount: formData.entry_cost_amount || null,
        payment_methods: formData.payment_methods || null,
        venue_photos: formData.venue_photos || [],
      };

      const response = await fetch(url, {
        method: selectedVenue ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dataToSend)
      });

      const result = await response.json();

      if (response.ok) {
        setShowForm(false);
        fetchVenues();
      } else {
        console.error('Server error:', result);
        alert('Error saving venue: ' + (result.message || 'Unknown error'));
      }
    } catch (error) {
      console.error('Error saving venue:', error);
      alert('Error saving venue. Please check the console.');
    }
  };

  if (onClose) {
    // Modal view
    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
        <div className="bg-white border border-brand-secondary rounded-md w-full max-w-6xl max-h-[90vh] overflow-y-auto">
          <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
            <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">Facility Management</h3>
            <button
              onClick={onClose}
              className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
            >
              ×
            </button>
          </div>

          <div className="p-6">
            <VenueListContent
              venues={venues}
              loading={loading}
              handleAddVenue={handleAddVenue}
              handleEditVenue={handleEditVenue}
              handleDeleteVenue={handleDeleteVenue}
            />
          </div>
        </div>

        {showForm && (
          <VenueForm
            formData={formData}
            setFormData={setFormData}
            selectedVenue={selectedVenue}
            newField={newField}
            setNewField={setNewField}
            handleAddField={handleAddField}
            handleRemoveField={handleRemoveField}
            handleSubmit={handleSubmit}
            onClose={() => setShowForm(false)}
            addressSearch={addressSearch}
            setAddressSearch={setAddressSearch}
            searchingAddress={searchingAddress}
            handleAddressSearch={handleAddressSearch}
          />
        )}
      </div>
    );
  }

  // Full page view
  return (
    <div>
      <VenueListContent
        venues={venues}
        loading={loading}
        handleAddVenue={handleAddVenue}
        handleEditVenue={handleEditVenue}
        handleDeleteVenue={handleDeleteVenue}
      />

      {showForm && (
        <VenueForm
          formData={formData}
          setFormData={setFormData}
          selectedVenue={selectedVenue}
          newField={newField}
          setNewField={setNewField}
          handleAddField={handleAddField}
          handleRemoveField={handleRemoveField}
          handleSubmit={handleSubmit}
          onClose={() => setShowForm(false)}
          addressSearch={addressSearch}
          setAddressSearch={setAddressSearch}
          searchingAddress={searchingAddress}
          handleAddressSearch={handleAddressSearch}
        />
      )}
    </div>
  );
};

const VenueListContent: React.FC<{
  venues: Venue[];
  loading: boolean;
  handleAddVenue: () => void;
  handleEditVenue: (venue: Venue) => void;
  handleDeleteVenue: (venueId: number) => void;
}> = ({ venues, loading, handleAddVenue, handleEditVenue, handleDeleteVenue }) => {
  return (
    <>
      <div className="mb-6 flex justify-between items-center">
        <div>
          <h2 className="text-3xl font-bold text-brand-primary uppercase tracking-wide">Facilities</h2>
          <p className="text-gray-600 mt-2">{venues.length} facilities total</p>
        </div>
        <button
          onClick={handleAddVenue}
          className="bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-2 hover:bg-brand-primary uppercase"
        >
          + Add Facility
        </button>
      </div>

      {loading ? (
        <div className="text-center text-brand-primary py-12">Loading facilities...</div>
      ) : venues.length === 0 ? (
        <div className="border border-brand-secondary rounded-md p-12 text-center bg-white">
          <p className="text-gray-600 text-lg">No facilities yet.</p>
          <p className="text-gray-500 mt-2">Click "Add Facility" to create your first facility.</p>
        </div>
      ) : (
        <div className="border border-brand-secondary rounded-md">
          <div className="overflow-x-auto">
          <table className="min-w-full bg-white">
            <thead>
              <tr className="border-b border-brand-secondary">
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Facility Name
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Address
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  City, State
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Details
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Fields
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Links
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Contacts
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {venues.map((venue) => (
                <tr key={venue.id} className="border-b border-gray-300 hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-brand-primary font-medium">{venue.name}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-brand-primary">{venue.address}</div>
                    {venue.zip && (
                      <div className="text-gray-500 text-sm">{venue.zip}</div>
                    )}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-brand-primary">
                      {venue.city}{venue.city && venue.state && ', '}{venue.state}
                    </div>
                  </td>
                  <td className="px-6 py-4 border-r border-gray-300">
                    <div className="flex flex-wrap gap-1">
                      {venue.venue_type && (
                        <span className="inline-block bg-blue-100 text-blue-800 text-xs font-semibold px-2 py-0.5 rounded">{venue.venue_type}</span>
                      )}
                      {venue.has_lights && (
                        <span className="inline-block bg-yellow-100 text-yellow-800 text-xs font-semibold px-2 py-0.5 rounded">Lights</span>
                      )}
                      {venue.parking_type && (
                        <span className="inline-block bg-gray-100 text-gray-800 text-xs font-semibold px-2 py-0.5 rounded">Parking: {venue.parking_type}</span>
                      )}
                      {venue.is_accessible && (
                        <span className="inline-block bg-green-100 text-green-800 text-xs font-semibold px-2 py-0.5 rounded">ADA</span>
                      )}
                      {venue.has_bathrooms && (
                        <span className="inline-block bg-purple-100 text-purple-800 text-xs font-semibold px-2 py-0.5 rounded">Bathrooms</span>
                      )}
                      {venue.has_concessions && (
                        <span className="inline-block bg-orange-100 text-orange-800 text-xs font-semibold px-2 py-0.5 rounded">Concessions</span>
                      )}
                      {venue.entry_cost && venue.entry_cost !== 'Free' && (
                        <span className="inline-block bg-red-100 text-red-800 text-xs font-semibold px-2 py-0.5 rounded">{venue.entry_cost}{venue.entry_cost_amount ? `: $${venue.entry_cost_amount}` : ''}</span>
                      )}
                      {(!venue.venue_type && !venue.has_lights && !venue.parking_type && !venue.is_accessible && !venue.has_bathrooms && !venue.has_concessions) && (
                        <span className="text-gray-400 text-xs">-</span>
                      )}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-brand-primary">
                      {venue.field_count || 0} field{venue.field_count !== 1 ? 's' : ''}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="flex space-x-3">
                      {venue.map_url && (
                        <a
                          href={venue.map_url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold"
                        >
                          Map
                        </a>
                      )}
                      {venue.website && (
                        <a
                          href={venue.website}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold"
                        >
                          Website
                        </a>
                      )}
                      {!venue.map_url && !venue.website && (
                        <span className="text-gray-400 text-xs">-</span>
                      )}
                    </div>
                  </td>
                  <td className="px-6 py-4 border-r border-gray-300">
                    <VenueContactsSummary venue={venue} />
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="flex space-x-3">
                      <button
                        onClick={() => handleEditVenue(venue)}
                        className="text-brand-primary hover:text-brand-primary-hover uppercase text-xs font-semibold"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => venue.id && handleDeleteVenue(venue.id)}
                        className="text-red-600 hover:text-red-500 uppercase text-xs font-semibold"
                      >
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        </div>
      )}
    </>
  );
};


const VenueFacilityDetailsSection: React.FC<{
  formData: Venue;
  setFormData: (data: Venue) => void;
}> = ({ formData, setFormData }) => {
  const [isOpen, setIsOpen] = useState(false);

  const inputClass = "border border-brand-secondary rounded-md px-3 py-2 text-sm text-brand-primary focus:ring-brand-primary focus:border-brand-primary w-full";
  const checkboxClass = "text-brand-primary focus:ring-brand-primary rounded";
  const labelClass = "block text-brand-primary text-xs font-medium mb-1 uppercase";

  const handlePaymentMethodToggle = (method: string) => {
    const current = formData.payment_methods ? formData.payment_methods.split(',').map(s => s.trim()).filter(Boolean) : [];
    const updated = current.includes(method) ? current.filter(m => m !== method) : [...current, method];
    setFormData({ ...formData, payment_methods: updated.join(', ') });
  };

  const paymentMethodsList = formData.payment_methods ? formData.payment_methods.split(',').map(s => s.trim()).filter(Boolean) : [];

  return (
    <div>
      <button
        type="button"
        onClick={() => setIsOpen(!isOpen)}
        className="w-full flex justify-between items-center mb-3"
      >
        <h4 className="text-sm font-bold text-brand-primary uppercase tracking-wide">Facility Details</h4>
        <span className="text-brand-primary text-sm">{isOpen ? '\u25B2' : '\u25BC'}</span>
      </button>

      {isOpen && (
        <div className="space-y-4 border border-brand-secondary rounded-md p-4">
          {/* Venue Type */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className={labelClass}>Venue Type</label>
              <select
                className={inputClass}
                value={formData.venue_type || ''}
                onChange={(e) => setFormData({ ...formData, venue_type: e.target.value })}
              >
                <option value="">Select type...</option>
                <option value="School">School</option>
                <option value="Stadium">Stadium</option>
                <option value="Gymnasium">Gymnasium</option>
                <option value="Field">Field</option>
                <option value="Park">Park</option>
                <option value="Indoor Facility">Indoor Facility</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>

          {/* Parking */}
          <div>
            <label className={labelClass}>Parking</label>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <select
                  className={inputClass}
                  value={formData.parking_type || ''}
                  onChange={(e) => setFormData({ ...formData, parking_type: e.target.value })}
                >
                  <option value="">Select parking type...</option>
                  <option value="Lot">Lot</option>
                  <option value="Street">Street</option>
                  <option value="Both">Both</option>
                </select>
              </div>
              <div className="flex items-center">
                <label className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    className={checkboxClass}
                    checked={formData.parking_paid || false}
                    onChange={(e) => setFormData({ ...formData, parking_paid: e.target.checked })}
                  />
                  <span className="text-brand-primary text-sm">Paid Parking</span>
                </label>
              </div>
              <div className="col-span-2">
                <textarea
                  className={inputClass}
                  value={formData.parking_notes || ''}
                  onChange={(e) => setFormData({ ...formData, parking_notes: e.target.value })}
                  placeholder="Parking notes (e.g., overflow lot on weekends)..."
                  rows={2}
                />
              </div>
            </div>
          </div>

          {/* Lights */}
          <div>
            <label className={labelClass}>Lights</label>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex items-center">
                <label className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    className={checkboxClass}
                    checked={formData.has_lights || false}
                    onChange={(e) => setFormData({ ...formData, has_lights: e.target.checked })}
                  />
                  <span className="text-brand-primary text-sm">Has Lights</span>
                </label>
              </div>
              <div>
                <input
                  type="text"
                  className={inputClass}
                  value={formData.lights_notes || ''}
                  onChange={(e) => setFormData({ ...formData, lights_notes: e.target.value })}
                  placeholder="Lights notes (e.g., available until 10pm)..."
                />
              </div>
            </div>
          </div>

          {/* Accessibility */}
          <div>
            <label className={labelClass}>Accessibility</label>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex items-center">
                <label className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    className={checkboxClass}
                    checked={formData.is_accessible || false}
                    onChange={(e) => setFormData({ ...formData, is_accessible: e.target.checked })}
                  />
                  <span className="text-brand-primary text-sm">ADA Compliant</span>
                </label>
              </div>
              <div>
                <input
                  type="text"
                  className={inputClass}
                  value={formData.accessibility_notes || ''}
                  onChange={(e) => setFormData({ ...formData, accessibility_notes: e.target.value })}
                  placeholder="Accessibility notes..."
                />
              </div>
            </div>
          </div>

          {/* Bathrooms */}
          <div>
            <label className={labelClass}>Bathrooms</label>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex items-center">
                <label className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    className={checkboxClass}
                    checked={formData.has_bathrooms || false}
                    onChange={(e) => setFormData({ ...formData, has_bathrooms: e.target.checked })}
                  />
                  <span className="text-brand-primary text-sm">Bathrooms Available</span>
                </label>
              </div>
              {formData.has_bathrooms && (
                <div>
                  <input
                    type="number"
                    className={inputClass}
                    value={formData.bathroom_count || ''}
                    onChange={(e) => setFormData({ ...formData, bathroom_count: e.target.value ? parseInt(e.target.value) : undefined })}
                    placeholder="Number of bathrooms"
                    min={0}
                  />
                </div>
              )}
            </div>
          </div>

          {/* Concessions */}
          <div>
            <label className={labelClass}>Concessions</label>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex items-center">
                <label className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    className={checkboxClass}
                    checked={formData.has_concessions || false}
                    onChange={(e) => setFormData({ ...formData, has_concessions: e.target.checked })}
                  />
                  <span className="text-brand-primary text-sm">Concessions Available</span>
                </label>
              </div>
              {formData.has_concessions && (
                <div>
                  <input
                    type="text"
                    className={inputClass}
                    value={formData.concessions_notes || ''}
                    onChange={(e) => setFormData({ ...formData, concessions_notes: e.target.value })}
                    placeholder="Concessions notes..."
                  />
                </div>
              )}
            </div>
          </div>

          {/* Seating */}
          <div>
            <label className={labelClass}>Seating</label>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <select
                  className={inputClass}
                  value={formData.seating_type || ''}
                  onChange={(e) => setFormData({ ...formData, seating_type: e.target.value })}
                >
                  <option value="">Select seating type...</option>
                  <option value="BYOC">BYOC (Bring Your Own Chair)</option>
                  <option value="Indoor Bleachers">Indoor Bleachers</option>
                  <option value="Outdoor Bleachers - Covered">Outdoor Bleachers - Covered</option>
                  <option value="Outdoor Bleachers - Uncovered">Outdoor Bleachers - Uncovered</option>
                  <option value="Mixed">Mixed</option>
                </select>
              </div>
              <div>
                <input
                  type="number"
                  className={inputClass}
                  value={formData.seating_capacity || ''}
                  onChange={(e) => setFormData({ ...formData, seating_capacity: e.target.value ? parseInt(e.target.value) : undefined })}
                  placeholder="Seating capacity"
                  min={0}
                />
              </div>
            </div>
          </div>

          {/* Entry Cost */}
          <div>
            <label className={labelClass}>Entry Cost</label>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <select
                  className={inputClass}
                  value={formData.entry_cost || ''}
                  onChange={(e) => setFormData({ ...formData, entry_cost: e.target.value })}
                >
                  <option value="">Select entry cost...</option>
                  <option value="Free">Free</option>
                  <option value="Fixed Price">Fixed Price</option>
                  <option value="Donation">Donation</option>
                </select>
              </div>
              {formData.entry_cost === 'Fixed Price' && (
                <div>
                  <input
                    type="number"
                    className={inputClass}
                    value={formData.entry_cost_amount || ''}
                    onChange={(e) => setFormData({ ...formData, entry_cost_amount: e.target.value ? parseFloat(e.target.value) : undefined })}
                    placeholder="Amount ($)"
                    min={0}
                    step={0.01}
                  />
                </div>
              )}
              <div className="col-span-2">
                <label className={labelClass}>Payment Methods</label>
                <div className="flex gap-4">
                  {['Cash', 'Card', 'Venmo'].map((method) => (
                    <label key={method} className="flex items-center space-x-2">
                      <input
                        type="checkbox"
                        className={checkboxClass}
                        checked={paymentMethodsList.includes(method)}
                        onChange={() => handlePaymentMethodToggle(method)}
                      />
                      <span className="text-brand-primary text-sm">{method}</span>
                    </label>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

const VenueForm: React.FC<{
  formData: Venue;
  setFormData: (data: Venue) => void;
  selectedVenue: Venue | null;
  newField: Field;
  setNewField: (field: Field) => void;
  handleAddField: () => void;
  handleRemoveField: (index: number) => void;
  handleSubmit: (e: React.FormEvent) => void;
  onClose: () => void;
  addressSearch?: string;
  setAddressSearch?: (value: string) => void;
  searchingAddress?: boolean;
  handleAddressSearch?: () => void;
}> = ({
  formData,
  setFormData,
  selectedVenue,
  newField,
  setNewField,
  handleAddField,
  handleRemoveField,
  handleSubmit,
  onClose,
}) => {
  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border border-brand-secondary rounded-md w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div className="border-b border-brand-secondary px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-brand-primary uppercase tracking-wide">
            {selectedVenue ? 'Edit Facility' : 'Create New Facility'}
          </h3>
          <button
            onClick={onClose}
            className="text-brand-primary hover:bg-gray-100 px-2 text-2xl"
          >
            ×
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          <div className="space-y-6">
            {/* Venue Information */}
            <div>
              <h4 className="text-brand-primary font-semibold mb-4 uppercase">Facility Information</h4>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Facility Name *
                  </label>
                  <input
                    type="text"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    required
                  />
                </div>

                <div className="col-span-2">
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Facility Address *
                  </label>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="md:col-span-2">
                      <GooglePlacesAutocomplete
                        defaultValue={formData.address}
                        placeholder="Search for an address or place..."
                        onPlaceSelect={(place) => {
                          setFormData({
                            ...formData,
                            address: place.address_line1,
                            city: place.city,
                            state: place.state,
                            zip: place.zip_code,
                            map_url: place.map_url || formData.map_url,
                          });
                        }}
                      />
                    </div>
                    <div>
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.city}
                        onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                        placeholder="City"
                      />
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.state}
                        onChange={(e) => setFormData({ ...formData, state: e.target.value })}
                        placeholder="State"
                        maxLength={2}
                      />
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.zip}
                        onChange={(e) => setFormData({ ...formData, zip: e.target.value })}
                        placeholder="Zip"
                        maxLength={10}
                      />
                    </div>
                  </div>
                </div>

                <div className="col-span-2">
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Google Maps URL
                  </label>
                  <textarea
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.map_url || ''}
                    onChange={(e) => setFormData({ ...formData, map_url: e.target.value })}
                    placeholder="Paste Google Maps link here (e.g., https://maps.google.com/...)"
                    rows={2}
                  />
                  <p className="text-gray-500 text-xs mt-1">
                    Go to Google Maps, find the facility, click "Share" and copy the link
                  </p>
                </div>

                <div className="col-span-2">
                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Website
                  </label>
                  <input
                    type="url"
                    className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                    value={formData.website || ''}
                    onChange={(e) => setFormData({ ...formData, website: e.target.value })}
                    placeholder="https://example.com"
                  />
                </div>
              </div>
            </div>

            {/* Contacts Section */}
            <VenueContactsSection formData={formData} setFormData={setFormData} />

            {/* Facility Details Section */}
            <VenueFacilityDetailsSection formData={formData} setFormData={setFormData} />

            {/* Fields Section */}
            <div>
              <h4 className="text-brand-primary font-semibold mb-4 uppercase">Fields</h4>

              {/* Add New Field Form */}
              <div className="border border-brand-secondary rounded-md p-4 mb-4">
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-1">Field Name</label>
                    <input
                      type="text"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-1 focus:outline-none focus:border-brand-accent"
                      value={newField.name}
                      onChange={(e) => setNewField({ ...newField, name: e.target.value })}
                      placeholder="e.g., Field 1"
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-1">Type</label>
                    <select
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-1 focus:outline-none focus:border-brand-accent"
                      value={newField.field_type}
                      onChange={(e) => setNewField({ ...newField, field_type: e.target.value })}
                    >
                      <option value="Soccer">Soccer</option>
                      <option value="Football">Football</option>
                      <option value="Baseball">Baseball</option>
                      <option value="Softball">Softball</option>
                      <option value="Multi-purpose">Multi-purpose</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-1">Surface</label>
                    <select
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-1 focus:outline-none focus:border-brand-accent"
                      value={newField.surface_type}
                      onChange={(e) => setNewField({ ...newField, surface_type: e.target.value })}
                    >
                      <option value="Grass">Grass</option>
                      <option value="Turf">Turf</option>
                      <option value="Indoor">Indoor</option>
                      <option value="Sand">Sand</option>
                      <option value="Court">Court</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-1">Dimensions</label>
                    <input
                      type="text"
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-1 focus:outline-none focus:border-brand-accent"
                      value={newField.dimensions}
                      onChange={(e) => setNewField({ ...newField, dimensions: e.target.value })}
                      placeholder="e.g., Full, U12"
                    />
                  </div>

                  <div>
                    <label className="block text-brand-primary text-sm font-medium mb-1">Status</label>
                    <select
                      className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-3 py-1 focus:outline-none focus:border-brand-accent"
                      value={newField.status}
                      onChange={(e) => setNewField({ ...newField, status: e.target.value as 'available' | 'maintenance' | 'closed' })}
                    >
                      <option value="available">Available</option>
                      <option value="maintenance">Maintenance</option>
                      <option value="closed">Closed</option>
                    </select>
                  </div>

                  <div className="flex items-end">
                    <label className="flex items-center space-x-2">
                      <input
                        type="checkbox"
                        className="border border-brand-secondary rounded-md"
                        checked={newField.has_lights}
                        onChange={(e) => setNewField({ ...newField, has_lights: e.target.checked })}
                      />
                      <span className="text-brand-primary text-sm">Has Lights</span>
                    </label>
                  </div>

                  <div className="flex items-end col-span-2">
                    <button
                      type="button"
                      onClick={handleAddField}
                      className="w-full bg-brand-primary text-white border border-brand-secondary rounded-md px-4 py-1 hover:bg-brand-primary uppercase text-sm"
                    >
                      + Add Field
                    </button>
                  </div>
                </div>
              </div>

              {/* Fields List */}
              {formData.fields && formData.fields.length > 0 && (
                <div className="border border-brand-secondary rounded-md">
                  <div className="overflow-x-auto">
                  <table className="min-w-full bg-white">
                    <thead>
                      <tr className="border-b border-brand-primary">
                        <th className="px-4 py-2 text-left text-xs font-bold text-brand-primary uppercase">Name</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-brand-primary uppercase">Type</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-brand-primary uppercase">Surface</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-brand-primary uppercase">Size</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-brand-primary uppercase">Lights</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-brand-primary uppercase">Status</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-brand-primary uppercase">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {formData.fields.map((field, index) => (
                        <tr key={index} className="border-b border-gray-300">
                          <td className="px-4 py-2 text-brand-primary">{field.name}</td>
                          <td className="px-4 py-2 text-brand-primary">{field.field_type}</td>
                          <td className="px-4 py-2 text-brand-primary">{field.surface_type}</td>
                          <td className="px-4 py-2 text-brand-primary">{field.dimensions || '-'}</td>
                          <td className="px-4 py-2 text-brand-primary">{field.has_lights ? 'Yes' : 'No'}</td>
                          <td className="px-4 py-2">
                            <span className={`px-2 py-1 text-xs font-semibold uppercase ${
                              field.status === 'available' ? 'bg-green-100 text-green-800' :
                              field.status === 'maintenance' ? 'bg-yellow-100 text-yellow-800' :
                              'bg-red-100 text-red-800'
                            }`}>
                              {field.status || 'available'}
                            </span>
                          </td>
                          <td className="px-4 py-2">
                            <button
                              type="button"
                              onClick={() => handleRemoveField(index)}
                              className="text-red-600 hover:text-red-500 uppercase text-xs font-semibold"
                            >
                              Remove
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  </div>
                </div>
              )}

              {(!formData.fields || formData.fields.length === 0) && (
                <p className="text-gray-500 text-center py-4">No fields added yet</p>
              )}
            </div>
          </div>

          <div className="flex justify-end space-x-4 mt-6">
            <button
              type="button"
              onClick={onClose}
              className="bg-white text-brand-primary border border-brand-secondary rounded-md px-6 py-2 hover:bg-gray-100 uppercase"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="bg-brand-primary text-white border border-brand-secondary rounded-md px-6 py-2 hover:bg-brand-primary font-semibold uppercase"
            >
              {selectedVenue ? 'Update Facility' : 'Create Facility'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};


const VenueContactsSummary: React.FC<{ venue: Venue }> = ({ venue }) => {
  const contacts = [
    { label: 'GM', name: venue.gm_contact_name, phone: venue.gm_contact_phone, email: venue.gm_contact_email },
    { label: 'Maintenance', name: venue.maintenance_contact_name, phone: venue.maintenance_contact_phone, email: venue.maintenance_contact_email },
    { label: 'Emergency', name: venue.emergency_contact_name, phone: venue.emergency_contact_phone, email: venue.emergency_contact_email },
    { label: 'Billing', name: venue.billing_contact_name, phone: venue.billing_contact_phone, email: venue.billing_contact_email },
  ].filter((c) => c.name || c.phone || c.email);

  if (contacts.length === 0) {
    return <span className="text-gray-400 text-xs">-</span>;
  }

  return (
    <div className="text-xs text-brand-primary space-y-1">
      {contacts.map((c) => (
        <div key={c.label}>
          <span className="font-semibold">{c.label}:</span>{' '}
          {[c.name, c.phone, c.email].filter(Boolean).join(' - ')}
        </div>
      ))}
    </div>
  );
};

const VenueContactsSection: React.FC<{
  formData: Venue;
  setFormData: (data: Venue) => void;
}> = ({ formData, setFormData }) => {
  const [openSections, setOpenSections] = useState<Record<string, boolean>>({});

  const toggle = (key: string) => {
    setOpenSections((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  const contactGroups = [
    { key: 'gm', label: 'General Manager', prefix: 'gm_contact' },
    { key: 'maintenance', label: 'Maintenance Contact', prefix: 'maintenance_contact' },
    { key: 'emergency', label: 'Emergency Contact', prefix: 'emergency_contact' },
    { key: 'billing', label: 'Billing Contact', prefix: 'billing_contact' },
  ] as const;

  return (
    <div>
      <h4 className="text-brand-primary font-semibold mb-4 uppercase">Contacts</h4>
      <div className="space-y-3">
        {contactGroups.map((group) => (
          <div key={group.key} className="border border-brand-secondary rounded-md">
            <button
              type="button"
              onClick={() => toggle(group.key)}
              className="w-full px-4 py-3 flex justify-between items-center hover:bg-gray-50"
            >
              <span className="text-sm font-bold text-brand-primary uppercase tracking-wide">{group.label}</span>
              <span className="text-brand-primary text-sm">{openSections[group.key] ? '−' : '+'}</span>
            </button>
            {openSections[group.key] && (
              <div className="px-4 pb-4">
                <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
                  <div className="md:col-span-2">
                    <label className="block text-brand-primary text-xs font-medium mb-1 uppercase">Name</label>
                    <input
                      type="text"
                      className="border border-brand-secondary rounded-md px-3 py-2 text-sm focus:ring-brand-primary focus:border-brand-primary w-full"
                      value={(formData as any)[`${group.prefix}_name`] || ''}
                      onChange={(e) => setFormData({ ...formData, [`${group.prefix}_name`]: e.target.value })}
                      placeholder="Full name"
                    />
                  </div>
                  <div>
                    <label className="block text-brand-primary text-xs font-medium mb-1 uppercase">Phone</label>
                    <input
                      type="tel"
                      className="border border-brand-secondary rounded-md px-3 py-2 text-sm focus:ring-brand-primary focus:border-brand-primary w-full"
                      value={(formData as any)[`${group.prefix}_phone`] || ''}
                      onChange={(e) => setFormData({ ...formData, [`${group.prefix}_phone`]: e.target.value })}
                      placeholder="Phone number"
                    />
                  </div>
                  <div>
                    <label className="block text-brand-primary text-xs font-medium mb-1 uppercase">Email</label>
                    <input
                      type="email"
                      className="border border-brand-secondary rounded-md px-3 py-2 text-sm focus:ring-brand-primary focus:border-brand-primary w-full"
                      value={(formData as any)[`${group.prefix}_email`] || ''}
                      onChange={(e) => setFormData({ ...formData, [`${group.prefix}_email`]: e.target.value })}
                      placeholder="Email address"
                    />
                  </div>
                </div>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
};

export default VenueManagement;