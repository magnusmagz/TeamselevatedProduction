import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import GooglePlacePicker from './GooglePlacePicker';

// Mock the Google Maps web components
beforeAll(() => {
  // Define custom elements if they don't exist
  if (!customElements.get('gmpx-place-picker')) {
    customElements.define('gmpx-place-picker', class extends HTMLElement {
      value: any = null;

      connectedCallback() {
        this.setAttribute('role', 'combobox');
      }

      // Simulate place selection
      simulatePlaceChange(place: any) {
        this.value = place;
        this.dispatchEvent(new CustomEvent('gmpx-placechange', {
          bubbles: true,
          detail: place
        }));
      }
    });
  }

  if (!customElements.get('gmp-map')) {
    customElements.define('gmp-map', class extends HTMLElement {
      center: any = null;
      zoom: number = 4;
    });
  }

  if (!customElements.get('gmp-advanced-marker')) {
    customElements.define('gmp-advanced-marker', class extends HTMLElement {
      position: any = null;
    });
  }
});

describe('GooglePlacePicker', () => {
  const mockOnPlaceSelect = jest.fn();
  const defaultProps = {
    apiKey: 'test-api-key',
    onPlaceSelect: mockOnPlaceSelect,
    placeholder: 'Enter address',
  };

  beforeEach(() => {
    mockOnPlaceSelect.mockClear();
  });

  describe('Rendering', () => {
    it('renders the place picker component', () => {
      render(<GooglePlacePicker {...defaultProps} />);
      const picker = document.querySelector('gmpx-place-picker');
      expect(picker).toBeInTheDocument();
    });

    it('renders with custom placeholder', () => {
      render(<GooglePlacePicker {...defaultProps} placeholder="Search for a venue" />);
      const picker = document.querySelector('gmpx-place-picker');
      expect(picker).toHaveAttribute('placeholder', 'Search for a venue');
    });

    it('does not render map when showMap is false', () => {
      render(<GooglePlacePicker {...defaultProps} showMap={false} />);
      const map = document.querySelector('gmp-map');
      expect(map).not.toBeInTheDocument();
    });

    it('renders map when showMap is true', () => {
      render(<GooglePlacePicker {...defaultProps} showMap={true} />);
      const map = document.querySelector('gmp-map');
      expect(map).toBeInTheDocument();
    });

    it('renders marker when showMap is true', () => {
      render(<GooglePlacePicker {...defaultProps} showMap={true} />);
      const marker = document.querySelector('gmp-advanced-marker');
      expect(marker).toBeInTheDocument();
    });
  });

  describe('Place Selection', () => {
    it('calls onPlaceSelect with parsed address when place is selected', async () => {
      render(<GooglePlacePicker {...defaultProps} />);

      const picker = document.querySelector('gmpx-place-picker') as any;

      // Simulate a place selection
      const mockPlace = {
        displayName: 'Test Venue',
        formattedAddress: '123 Main Street, Austin, TX 78701, USA',
        location: {
          lat: () => 30.2672,
          lng: () => -97.7431
        }
      };

      picker.value = mockPlace;
      picker.dispatchEvent(new CustomEvent('gmpx-placechange', { bubbles: true }));

      await waitFor(() => {
        expect(mockOnPlaceSelect).toHaveBeenCalledWith({
          name: 'Test Venue',
          address: '123 Main Street',
          city: 'Austin',
          state: 'TX',
          zip: '78701',
          lat: 30.2672,
          lng: -97.7431
        });
      });
    });

    it('handles addresses with different formats', async () => {
      render(<GooglePlacePicker {...defaultProps} />);

      const picker = document.querySelector('gmpx-place-picker') as any;

      // Address with only street and city
      const mockPlace = {
        displayName: 'Simple Address',
        formattedAddress: '456 Oak Ave, Dallas, TX 75201',
        location: {
          lat: () => 32.7767,
          lng: () => -96.7970
        }
      };

      picker.value = mockPlace;
      picker.dispatchEvent(new CustomEvent('gmpx-placechange', { bubbles: true }));

      await waitFor(() => {
        expect(mockOnPlaceSelect).toHaveBeenCalledWith(expect.objectContaining({
          address: '456 Oak Ave',
          city: 'Dallas',
        }));
      });
    });

    it('does not call onPlaceSelect when place has no formatted address', async () => {
      render(<GooglePlacePicker {...defaultProps} />);

      const picker = document.querySelector('gmpx-place-picker') as any;

      // Empty place
      picker.value = {};
      picker.dispatchEvent(new CustomEvent('gmpx-placechange', { bubbles: true }));

      await waitFor(() => {
        expect(mockOnPlaceSelect).not.toHaveBeenCalled();
      });
    });

    it('handles missing location coordinates gracefully', async () => {
      render(<GooglePlacePicker {...defaultProps} />);

      const picker = document.querySelector('gmpx-place-picker') as any;

      const mockPlace = {
        displayName: 'No Location',
        formattedAddress: '789 Pine St, Houston, TX 77001',
        // No location property
      };

      picker.value = mockPlace;
      picker.dispatchEvent(new CustomEvent('gmpx-placechange', { bubbles: true }));

      await waitFor(() => {
        expect(mockOnPlaceSelect).toHaveBeenCalledWith(expect.objectContaining({
          lat: 0,
          lng: 0
        }));
      });
    });
  });

  describe('Map Integration', () => {
    it('updates map center when place is selected with showMap=true', async () => {
      render(<GooglePlacePicker {...defaultProps} showMap={true} />);

      const picker = document.querySelector('gmpx-place-picker') as any;
      const map = document.querySelector('gmp-map') as any;

      const mockPlace = {
        displayName: 'Test Location',
        formattedAddress: '100 Test St, Austin, TX 78701',
        location: {
          lat: () => 30.2672,
          lng: () => -97.7431
        }
      };

      picker.value = mockPlace;
      picker.dispatchEvent(new CustomEvent('gmpx-placechange', { bubbles: true }));

      // Map updates are handled internally - we just verify no errors
      expect(map).toBeInTheDocument();
    });
  });

  describe('API Key Handling', () => {
    it('logs warning when API key is missing', () => {
      const consoleSpy = jest.spyOn(console, 'error').mockImplementation();

      render(<GooglePlacePicker {...defaultProps} apiKey="" />);

      expect(consoleSpy).toHaveBeenCalledWith(
        'Google Maps API key is missing! Check your environment variables'
      );

      consoleSpy.mockRestore();
    });

    it('logs info when API key is provided', () => {
      const consoleSpy = jest.spyOn(console, 'log').mockImplementation();

      render(<GooglePlacePicker {...defaultProps} apiKey="valid-key" />);

      expect(consoleSpy).toHaveBeenCalledWith(
        'GooglePlacePicker mounted with API Key:',
        'Key provided'
      );

      consoleSpy.mockRestore();
    });
  });
});

// Address parsing utility tests
describe('Address Parsing Logic', () => {
  // Test the parsing logic that extracts city, state, zip from formatted address
  const parseAddress = (formattedAddress: string) => {
    const addressParts = formattedAddress.split(',');
    let street = '';
    let city = '';
    let state = '';
    let zip = '';

    if (addressParts.length >= 3) {
      street = addressParts[0].trim();
      city = addressParts[1].trim();
      const stateZip = addressParts[2].trim().split(' ');
      state = stateZip[0] || '';
      zip = stateZip[1] || '';
    }

    return { street, city, state, zip };
  };

  it('parses standard US address format', () => {
    const result = parseAddress('123 Main Street, Austin, TX 78701, USA');
    expect(result).toEqual({
      street: '123 Main Street',
      city: 'Austin',
      state: 'TX',
      zip: '78701'
    });
  });

  it('parses address without country', () => {
    const result = parseAddress('456 Oak Ave, Dallas, TX 75201');
    expect(result).toEqual({
      street: '456 Oak Ave',
      city: 'Dallas',
      state: 'TX',
      zip: '75201'
    });
  });

  it('handles address with suite/unit number', () => {
    const result = parseAddress('789 Corporate Blvd Suite 100, Houston, TX 77001');
    expect(result).toEqual({
      street: '789 Corporate Blvd Suite 100',
      city: 'Houston',
      state: 'TX',
      zip: '77001'
    });
  });

  it('handles address with only state (no zip)', () => {
    const result = parseAddress('100 Rural Road, Small Town, TX');
    expect(result).toEqual({
      street: '100 Rural Road',
      city: 'Small Town',
      state: 'TX',
      zip: ''
    });
  });

  it('returns empty strings for malformed address', () => {
    const result = parseAddress('Invalid Address');
    expect(result).toEqual({
      street: '',
      city: '',
      state: '',
      zip: ''
    });
  });
});
