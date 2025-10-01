import React, { useState, useEffect } from 'react';
import GooglePlacePicker from './GooglePlacePicker';

interface Field {
  name: string;
  field_type: string;
  surface: string;
  size: string;
  lights: boolean;
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
  const [venues, setVenues] = useState<Venue[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [selectedVenue, setSelectedVenue] = useState<Venue | null>(null);
  const [loading, setLoading] = useState(true);

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
    surface: 'Grass',
    size: '',
    lights: false
  });

  useEffect(() => {
    fetchVenues();
  }, []);

  const fetchVenues = async () => {
    try {
      const response = await fetch('http://localhost:8889/venues-gateway.php');
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
      const response = await fetch(`http://localhost:8889/venues-gateway.php?id=${venue.id}`);
      const data = await response.json();
      setSelectedVenue(data);
      setFormData(data);
      setShowForm(true);
    } catch (error) {
      console.error('Error fetching venue details:', error);
    }
  };

  const handleDeleteVenue = async (venueId: number) => {
    if (!window.confirm('Are you sure you want to delete this venue? This will also delete all associated fields.')) {
      return;
    }

    try {
      const response = await fetch(`http://localhost:8889/venues-gateway.php?id=${venueId}`, {
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
        surface: 'Grass',
        size: '',
        lights: false
      });
    }
  };

  const handleRemoveField = (index: number) => {
    const updatedFields = formData.fields?.filter((_, i) => i !== index) || [];
    setFormData({ ...formData, fields: updatedFields });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      const url = selectedVenue
        ? `http://localhost:8889/venues-gateway.php?id=${selectedVenue.id}`
        : 'http://localhost:8889/venues-gateway.php';

      // Clean up the data to send - remove undefined/empty fields array
      const dataToSend = {
        name: formData.name,
        address: formData.address,
        city: formData.city,
        state: formData.state,
        zip: formData.zip,
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
        <div className="bg-white border-2 border-forest-800 w-full max-w-6xl max-h-[90vh] overflow-y-auto">
          <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center">
            <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">Venue Management</h3>
            <button
              onClick={onClose}
              className="text-forest-800 hover:bg-gray-100 px-2 text-2xl"
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
          <h2 className="text-3xl font-bold text-forest-800 uppercase tracking-wide">Venues</h2>
          <p className="text-gray-600 mt-2">{venues.length} venues total</p>
        </div>
        <button
          onClick={handleAddVenue}
          className="bg-forest-800 text-white border-2 border-forest-800 px-4 py-2 hover:bg-forest-700 uppercase"
        >
          + Add Venue
        </button>
      </div>

      {loading ? (
        <div className="text-center text-forest-800 py-12">Loading venues...</div>
      ) : venues.length === 0 ? (
        <div className="border-2 border-forest-800 p-12 text-center bg-white">
          <p className="text-gray-600 text-lg">No venues yet.</p>
          <p className="text-gray-500 mt-2">Click "Add Venue" to create your first venue.</p>
        </div>
      ) : (
        <div className="border-2 border-forest-800">
          <table className="min-w-full bg-white">
            <thead>
              <tr className="border-b-2 border-forest-800">
                <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase border-r border-gray-300">
                  Venue Name
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase border-r border-gray-300">
                  Address
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase border-r border-gray-300">
                  City, State
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase border-r border-gray-300">
                  Fields
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase border-r border-gray-300">
                  Links
                </th>
                <th className="px-6 py-3 text-left text-xs font-bold text-forest-800 uppercase">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {venues.map((venue) => (
                <tr key={venue.id} className="border-b border-gray-300 hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-forest-800 font-medium">{venue.name}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-forest-800">{venue.address}</div>
                    {venue.zip && (
                      <div className="text-gray-500 text-sm">{venue.zip}</div>
                    )}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-forest-800">
                      {venue.city}{venue.city && venue.state && ', '}{venue.state}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap border-r border-gray-300">
                    <div className="text-forest-800">
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
                          className="text-forest-800 hover:text-forest-600 uppercase text-xs font-semibold"
                        >
                          Map
                        </a>
                      )}
                      {venue.website && (
                        <a
                          href={venue.website}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-forest-800 hover:text-forest-600 uppercase text-xs font-semibold"
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
                        className="text-forest-800 hover:text-forest-600 uppercase text-xs font-semibold"
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
}> = ({
  formData,
  setFormData,
  selectedVenue,
  newField,
  setNewField,
  handleAddField,
  handleRemoveField,
  handleSubmit,
  onClose
}) => {
  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white border-2 border-forest-800 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div className="border-b-2 border-forest-800 px-6 py-4 flex justify-between items-center">
          <h3 className="text-xl font-semibold text-forest-800 uppercase tracking-wide">
            {selectedVenue ? 'Edit Venue' : 'Create New Venue'}
          </h3>
          <button
            onClick={onClose}
            className="text-forest-800 hover:bg-gray-100 px-2 text-2xl"
          >
            ×
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6">
          <div className="space-y-6">
            {/* Venue Information */}
            <div>
              <h4 className="text-forest-800 font-semibold mb-4 uppercase">Venue Information</h4>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Venue Name *
                  </label>
                  <input
                    type="text"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    required
                  />
                </div>

                <div className="col-span-2">
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Venue Address * (Start typing and select from suggestions)
                  </label>
                  <GooglePlacePicker
                    apiKey={process.env.REACT_APP_GOOGLE_MAPS_API_KEY || ''}
                    placeholder="Enter venue address..."
                    onPlaceSelect={(place) => {
                      setFormData({
                        ...formData,
                        address: place.address,
                        city: place.city,
                        state: place.state,
                        zip: place.zip
                        // Note: lat/lng stored in place object if needed later
                      });
                    }}
                    showMap={false}
                  />
                  <div className="grid grid-cols-3 gap-2 mt-2">
                    <div className="text-xs text-gray-600">
                      <span className="font-semibold">City:</span> {formData.city || 'Not set'}
                    </div>
                    <div className="text-xs text-gray-600">
                      <span className="font-semibold">State:</span> {formData.state || 'Not set'}
                    </div>
                    <div className="text-xs text-gray-600">
                      <span className="font-semibold">Zip:</span> {formData.zip || 'Not set'}
                    </div>
                  </div>
                </div>

                <div className="col-span-2">
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Google Maps URL
                  </label>
                  <textarea
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.map_url || ''}
                    onChange={(e) => setFormData({ ...formData, map_url: e.target.value })}
                    placeholder="Paste Google Maps link here (e.g., https://maps.google.com/...)"
                    rows={2}
                  />
                  <p className="text-gray-500 text-xs mt-1">
                    Go to Google Maps, find the venue, click "Share" and copy the link
                  </p>
                </div>

                <div className="col-span-2">
                  <label className="block text-forest-800 text-sm font-medium mb-2 uppercase">
                    Website
                  </label>
                  <input
                    type="url"
                    className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
                    value={formData.website || ''}
                    onChange={(e) => setFormData({ ...formData, website: e.target.value })}
                    placeholder="https://example.com"
                  />
                </div>
              </div>
            </div>

            {/* Fields Section */}
            <div>
              <h4 className="text-forest-800 font-semibold mb-4 uppercase">Fields</h4>

              {/* Add New Field Form */}
              <div className="border-2 border-forest-800 p-4 mb-4">
                <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-1">Field Name</label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-3 py-1 focus:outline-none focus:border-forest-600"
                      value={newField.name}
                      onChange={(e) => setNewField({ ...newField, name: e.target.value })}
                      placeholder="e.g., Field 1"
                    />
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-1">Type</label>
                    <select
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-3 py-1 focus:outline-none focus:border-forest-600"
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
                    <label className="block text-forest-800 text-sm font-medium mb-1">Surface</label>
                    <select
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-3 py-1 focus:outline-none focus:border-forest-600"
                      value={newField.surface}
                      onChange={(e) => setNewField({ ...newField, surface: e.target.value })}
                    >
                      <option value="Grass">Grass</option>
                      <option value="Turf">Turf</option>
                      <option value="Dirt">Dirt</option>
                      <option value="Indoor">Indoor</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-forest-800 text-sm font-medium mb-1">Size</label>
                    <input
                      type="text"
                      className="w-full bg-white text-forest-800 border-2 border-forest-800 px-3 py-1 focus:outline-none focus:border-forest-600"
                      value={newField.size}
                      onChange={(e) => setNewField({ ...newField, size: e.target.value })}
                      placeholder="e.g., Full, U12"
                    />
                  </div>

                  <div className="flex items-end">
                    <label className="flex items-center space-x-2">
                      <input
                        type="checkbox"
                        className="border-2 border-forest-800"
                        checked={newField.lights}
                        onChange={(e) => setNewField({ ...newField, lights: e.target.checked })}
                      />
                      <span className="text-forest-800 text-sm">Has Lights</span>
                    </label>
                  </div>

                  <div className="flex items-end">
                    <button
                      type="button"
                      onClick={handleAddField}
                      className="w-full bg-forest-800 text-white border-2 border-forest-800 px-4 py-1 hover:bg-forest-700 uppercase text-sm"
                    >
                      + Add Field
                    </button>
                  </div>
                </div>
              </div>

              {/* Fields List */}
              {formData.fields && formData.fields.length > 0 && (
                <div className="border-2 border-forest-800">
                  <table className="min-w-full bg-white">
                    <thead>
                      <tr className="border-b border-forest-800">
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Name</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Type</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Surface</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Size</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Lights</th>
                        <th className="px-4 py-2 text-left text-xs font-bold text-forest-800 uppercase">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {formData.fields.map((field, index) => (
                        <tr key={index} className="border-b border-gray-300">
                          <td className="px-4 py-2 text-forest-800">{field.name}</td>
                          <td className="px-4 py-2 text-forest-800">{field.field_type}</td>
                          <td className="px-4 py-2 text-forest-800">{field.surface}</td>
                          <td className="px-4 py-2 text-forest-800">{field.size || '-'}</td>
                          <td className="px-4 py-2 text-forest-800">{field.lights ? 'Yes' : 'No'}</td>
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
              className="bg-white text-forest-800 border-2 border-forest-800 px-6 py-2 hover:bg-gray-100 uppercase"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="bg-forest-800 text-white border-2 border-forest-800 px-6 py-2 hover:bg-forest-700 font-semibold uppercase"
            >
              {selectedVenue ? 'Update Venue' : 'Create Venue'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default VenueManagement;