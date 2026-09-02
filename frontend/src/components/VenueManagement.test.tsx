import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import VenueManagement from './VenueManagement';

// Mock fetch globally
const mockFetch = jest.fn();
global.fetch = mockFetch;

// Mock the address/place picker the form actually renders.
// (The form used to use GooglePlacePicker; it now uses GooglePlacesAutocomplete,
// which loads the Google Maps script at mount — mocked here to keep the suite
// hermetic, exactly as the old picker was.)
jest.mock('./GooglePlacesAutocomplete', () => {
  return function MockGooglePlacesAutocomplete({ onPlaceSelect, placeholder, defaultValue }: any) {
    return (
      <input
        type="text"
        placeholder={placeholder}
        defaultValue={defaultValue}
        data-testid="place-picker-input"
        onChange={(e) => {
          // Simulate place selection on input
          if (e.target.value === 'Test Address') {
            onPlaceSelect({
              name: 'Test Venue',
              formatted_address: '123 Test St, Austin, TX 78701',
              address_line1: '123 Test St',
              city: 'Austin',
              state: 'TX',
              zip_code: '78701',
              country: 'USA',
              lat: 30.2672,
              lng: -97.7431
            });
          }
        }}
      />
    );
  };
});

// Mock window.confirm
const mockConfirm = jest.fn();
window.confirm = mockConfirm;

// Sample venue data
const mockVenues = [
  {
    id: 1,
    name: 'Soccer Complex',
    address: '100 Field Road',
    city: 'Austin',
    state: 'TX',
    zip: '78701',
    map_url: 'https://maps.google.com/test',
    website: 'https://soccercomplex.com',
    field_count: 3
  },
  {
    id: 2,
    name: 'Sports Center',
    address: '200 Arena Blvd',
    city: 'Dallas',
    state: 'TX',
    zip: '75201',
    field_count: 5
  }
];

const mockVenueWithFields = {
  id: 1,
  name: 'Soccer Complex',
  address: '100 Field Road',
  city: 'Austin',
  state: 'TX',
  zip: '78701',
  map_url: 'https://maps.google.com/test',
  website: 'https://soccercomplex.com',
  fields: [
    {
      name: 'Field 1',
      field_type: 'Soccer',
      surface_type: 'Grass',
      dimensions: 'Full',
      has_lights: true,
      status: 'available'
    },
    {
      name: 'Field 2',
      field_type: 'Soccer',
      surface_type: 'Turf',
      dimensions: 'U12',
      has_lights: false,
      status: 'maintenance'
    }
  ]
};

describe('VenueManagement', () => {
  beforeEach(() => {
    mockFetch.mockClear();
    mockConfirm.mockClear();
  });

  describe('Loading State', () => {
    it('shows loading message while fetching venues', () => {
      mockFetch.mockImplementation(() => new Promise(() => {})); // Never resolves

      render(<VenueManagement />);

      expect(screen.getByText('Loading facilities...')).toBeInTheDocument();
    });
  });

  describe('Empty State', () => {
    it('shows empty state when no venues exist', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => []
      });

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('No facilities yet.')).toBeInTheDocument();
      });

      expect(screen.getByText('Click "Add Facility" to create your first facility.')).toBeInTheDocument();
    });
  });

  describe('Venue List', () => {
    it('displays venues in a table', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => mockVenues
      });

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('Soccer Complex')).toBeInTheDocument();
      });

      expect(screen.getByText('Sports Center')).toBeInTheDocument();
      expect(screen.getByText('100 Field Road')).toBeInTheDocument();
      expect(screen.getByText('200 Arena Blvd')).toBeInTheDocument();
    });

    it('shows venue count in header', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => mockVenues
      });

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('2 facilities total')).toBeInTheDocument();
      });
    });

    it('displays field count for each venue', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => mockVenues
      });

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('3 fields')).toBeInTheDocument();
        expect(screen.getByText('5 fields')).toBeInTheDocument();
      });
    });

    it('displays Map and Website links when available', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => mockVenues
      });

      render(<VenueManagement />);

      await waitFor(() => {
        const mapLinks = screen.getAllByText('Map');
        const websiteLinks = screen.getAllByText('Website');

        expect(mapLinks.length).toBe(1); // Only first venue has map_url
        expect(websiteLinks.length).toBe(1); // Only first venue has website
      });
    });
  });

  describe('Add Venue', () => {
    it('opens form when Add Venue button is clicked', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => []
      });

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('+ Add Facility')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByText('+ Add Facility'));

      expect(screen.getByText('Create New Facility')).toBeInTheDocument();
    });

    it('shows venue form fields', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => []
      });

      render(<VenueManagement />);

      await waitFor(() => {
        fireEvent.click(screen.getByText('+ Add Facility'));
      });

      expect(screen.getByText('Facility Name *')).toBeInTheDocument();
      expect(screen.getByText('Google Maps URL')).toBeInTheDocument();
      expect(screen.getByText('Website')).toBeInTheDocument();
      expect(screen.getByPlaceholderText('Search by facility name or address...')).toBeInTheDocument();
    });

    it('submits new venue successfully', async () => {
      mockFetch
        .mockResolvedValueOnce({ ok: true, json: async () => [] }) // Initial load
        .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true, id: 3 }) }) // Create
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenues }); // Reload

      render(<VenueManagement />);

      await waitFor(() => {
        fireEvent.click(screen.getByText('+ Add Facility'));
      });

      // Fill in venue name - find input by placeholder or container
      const formInputs = document.querySelectorAll('input[type="text"]');
      const nameInput = formInputs[0]; // First text input is venue name
      fireEvent.change(nameInput, { target: { value: 'New Test Venue' } });

      // Submit form
      fireEvent.click(screen.getByText('Create Facility'));

      await waitFor(() => {
        expect(mockFetch).toHaveBeenCalledWith(
          expect.stringContaining('/legacy/venues-gateway.php'),
          expect.objectContaining({
            method: 'POST',
            // The gateway now also carries the bearer token, so assert on the
            // content type rather than the whole headers object.
            headers: expect.objectContaining({ 'Content-Type': 'application/json' })
          })
        );
      });
    });

    it('closes form when Cancel is clicked', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => []
      });

      render(<VenueManagement />);

      await waitFor(() => {
        fireEvent.click(screen.getByText('+ Add Facility'));
      });

      expect(screen.getByText('Create New Facility')).toBeInTheDocument();

      fireEvent.click(screen.getByText('Cancel'));

      await waitFor(() => {
        expect(screen.queryByText('Create New Facility')).not.toBeInTheDocument();
      });
    });
  });

  describe('Edit Facility', () => {
    it('opens form with venue data when Edit is clicked', async () => {
      mockFetch
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenues }) // Initial load
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenueWithFields }); // Fetch venue details

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('Soccer Complex')).toBeInTheDocument();
      });

      const editButtons = screen.getAllByText('Edit');
      fireEvent.click(editButtons[0]);

      await waitFor(() => {
        expect(screen.getByText('Edit Facility')).toBeInTheDocument();
      });
    });

    it('updates venue on submit', async () => {
      mockFetch
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenues })
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenueWithFields })
        .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true }) })
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenues });

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('Soccer Complex')).toBeInTheDocument();
      });

      const editButtons = screen.getAllByText('Edit');
      fireEvent.click(editButtons[0]);

      await waitFor(() => {
        expect(screen.getByText('Edit Facility')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByText('Update Facility'));

      await waitFor(() => {
        expect(mockFetch).toHaveBeenCalledWith(
          expect.stringContaining('id=1'),
          expect.objectContaining({ method: 'PUT' })
        );
      });
    });
  });

  describe('Delete Venue', () => {
    it('shows confirmation dialog before deleting', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => mockVenues
      });

      mockConfirm.mockReturnValue(false);

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('Soccer Complex')).toBeInTheDocument();
      });

      const deleteButtons = screen.getAllByText('Delete');
      fireEvent.click(deleteButtons[0]);

      expect(mockConfirm).toHaveBeenCalledWith(
        'Are you sure you want to delete this facility? This will also delete all associated fields.'
      );
    });

    it('deletes venue when confirmed', async () => {
      mockFetch
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenues })
        .mockResolvedValueOnce({ ok: true })
        .mockResolvedValueOnce({ ok: true, json: async () => [mockVenues[1]] });

      mockConfirm.mockReturnValue(true);

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('Soccer Complex')).toBeInTheDocument();
      });

      const deleteButtons = screen.getAllByText('Delete');
      fireEvent.click(deleteButtons[0]);

      await waitFor(() => {
        expect(mockFetch).toHaveBeenCalledWith(
          expect.stringContaining('id=1'),
          expect.objectContaining({ method: 'DELETE' })
        );
      });
    });

    it('does not delete when cancelled', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => mockVenues
      });

      mockConfirm.mockReturnValue(false);

      render(<VenueManagement />);

      await waitFor(() => {
        expect(screen.getByText('Soccer Complex')).toBeInTheDocument();
      });

      const deleteButtons = screen.getAllByText('Delete');
      fireEvent.click(deleteButtons[0]);

      // Only the initial fetch should have been called
      expect(mockFetch).toHaveBeenCalledTimes(1);
    });
  });

  describe('Field Management', () => {
    it('adds a new field to the form', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => []
      });

      render(<VenueManagement />);

      await waitFor(() => {
        fireEvent.click(screen.getByText('+ Add Facility'));
      });

      // Fill in field name
      const fieldNameInput = screen.getByPlaceholderText('e.g., Field 1');
      fireEvent.change(fieldNameInput, { target: { value: 'Main Field' } });

      // Click Add Field
      fireEvent.click(screen.getByText('+ Add Field'));

      // Field should appear in the list
      await waitFor(() => {
        expect(screen.getByText('Main Field')).toBeInTheDocument();
      });
    });

    it('removes a field from the form', async () => {
      mockFetch
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenues })
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenueWithFields });

      render(<VenueManagement />);

      await waitFor(() => {
        const editButtons = screen.getAllByText('Edit');
        fireEvent.click(editButtons[0]);
      });

      await waitFor(() => {
        expect(screen.getByText('Field 1')).toBeInTheDocument();
      });

      const removeButtons = screen.getAllByText('Remove');
      fireEvent.click(removeButtons[0]);

      await waitFor(() => {
        expect(screen.queryByText('Field 1')).not.toBeInTheDocument();
      });
    });

    it('displays field status with correct styling', async () => {
      mockFetch
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenues })
        .mockResolvedValueOnce({ ok: true, json: async () => mockVenueWithFields });

      render(<VenueManagement />);

      await waitFor(() => {
        const editButtons = screen.getAllByText('Edit');
        fireEvent.click(editButtons[0]);
      });

      await waitFor(() => {
        // Check for status badges
        expect(screen.getByText('available')).toBeInTheDocument();
        expect(screen.getByText('maintenance')).toBeInTheDocument();
      });
    });

    it('allows selecting field status', async () => {
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => []
      });

      render(<VenueManagement />);

      await waitFor(() => {
        fireEvent.click(screen.getByText('+ Add Facility'));
      });

      // Find status dropdown by its displayed value
      const statusSelect = screen.getByDisplayValue('Available');

      // Status options should be available
      expect(statusSelect).toBeInTheDocument();
    });
  });

  describe('Modal Mode', () => {
    it('renders as modal when onClose prop is provided', async () => {
      const mockOnClose = jest.fn();
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => mockVenues
      });

      render(<VenueManagement onClose={mockOnClose} />);

      await waitFor(() => {
        expect(screen.getByText('Facility Management')).toBeInTheDocument();
      });

      // Should have close button
      const closeButton = screen.getByText('×');
      expect(closeButton).toBeInTheDocument();
    });

    it('calls onClose when close button is clicked', async () => {
      const mockOnClose = jest.fn();
      mockFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => mockVenues
      });

      render(<VenueManagement onClose={mockOnClose} />);

      await waitFor(() => {
        expect(screen.getByText('Facility Management')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByText('×'));

      expect(mockOnClose).toHaveBeenCalled();
    });
  });

  describe('Error Handling', () => {
    it('handles API errors gracefully', async () => {
      const consoleSpy = jest.spyOn(console, 'error').mockImplementation();

      mockFetch.mockRejectedValueOnce(new Error('Network error'));

      render(<VenueManagement />);

      await waitFor(() => {
        expect(consoleSpy).toHaveBeenCalledWith('Error fetching venues:', expect.any(Error));
      });

      consoleSpy.mockRestore();
    });

    it('shows alert on save error', async () => {
      const alertSpy = jest.spyOn(window, 'alert').mockImplementation();

      mockFetch
        .mockResolvedValueOnce({ ok: true, json: async () => [] })
        .mockResolvedValueOnce({ ok: false, json: async () => ({ message: 'Save failed' }) });

      render(<VenueManagement />);

      await waitFor(() => {
        fireEvent.click(screen.getByText('+ Add Facility'));
      });

      const formInputs = document.querySelectorAll('input[type="text"]');
      const nameInput = formInputs[0];
      fireEvent.change(nameInput, { target: { value: 'Test Venue' } });

      fireEvent.click(screen.getByText('Create Facility'));

      await waitFor(() => {
        expect(alertSpy).toHaveBeenCalledWith('Error saving venue: Save failed');
      });

      alertSpy.mockRestore();
    });
  });
});
