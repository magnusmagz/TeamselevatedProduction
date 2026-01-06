import React, { useState, useEffect } from 'react';
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

  const [formData, setFormData] = useState<Venue>({
    name: '',
    address: '',
    city: '',
    state: '',
    zip: '',
    map_url: '',
    website: '',
    fields: []
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
    console.log('Google Maps API Key available:', !!process.env.REACT_APP_GOOGLE_MAPS_API_KEY);
    console.log('API Key value:', process.env.REACT_APP_GOOGLE_MAPS_API_KEY);
    setSelectedVenue(null);
    setFormData({
      name: '',
      address: '',
      city: '',
      state: '',
      zip: '',
      map_url: '',
      website: '',
      fields: []
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
        fields: formData.fields && formData.fields.length > 0 ? formData.fields : []
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
                  Fields
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-brand-primary uppercase border-r border-gray-300">
                  Links
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
      )}
    </>
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
  addressSearch: string;
  setAddressSearch: (value: string) => void;
  searchingAddress: boolean;
  handleAddressSearch: () => void;
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
  addressSearch,
  setAddressSearch,
  searchingAddress,
  handleAddressSearch
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
                    Search Address (Optional)
                  </label>
                  <div className="flex gap-2 mb-4">
                    <input
                      type="text"
                      className="flex-1 bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                      value={addressSearch}
                      onChange={(e) => setAddressSearch(e.target.value)}
                      onKeyPress={(e) => e.key === 'Enter' && (e.preventDefault(), handleAddressSearch())}
                      placeholder="Type address and click Search..."
                    />
                    <button
                      type="button"
                      onClick={handleAddressSearch}
                      disabled={searchingAddress || !addressSearch.trim()}
                      className="bg-brand-primary text-white px-4 py-2 hover:bg-brand-primary disabled:bg-gray-400 disabled:cursor-not-allowed"
                    >
                      {searchingAddress ? 'Searching...' : 'Search'}
                    </button>
                  </div>

                  <label className="block text-brand-primary text-sm font-medium mb-2 uppercase">
                    Facility Address *
                  </label>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="md:col-span-2">
                      <input
                        type="text"
                        className="w-full bg-white text-brand-primary border border-brand-secondary rounded-md px-4 py-2 focus:outline-none focus:border-brand-accent"
                        value={formData.address}
                        onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                        placeholder="Street Address"
                        required
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

export default VenueManagement;