import React, { useEffect, useRef } from 'react';

declare global {
  namespace JSX {
    interface IntrinsicElements {
      'gmpx-api-loader': any;
      'gmpx-place-picker': any;
      'gmp-map': any;
      'gmp-advanced-marker': any;
    }
  }
}

interface PlaceDetails {
  displayName: string;
  formattedAddress: string;
  location?: {
    lat: () => number;
    lng: () => number;
  };
  addressComponents?: any[];
}

interface GooglePlacePickerProps {
  onPlaceSelect: (place: {
    name: string;
    address: string;
    city: string;
    state: string;
    zip: string;
    lat: number;
    lng: number;
  }) => void;
  placeholder?: string;
  showMap?: boolean;
  className?: string;
  apiKey: string;
}

const GooglePlacePicker: React.FC<GooglePlacePickerProps> = ({
  onPlaceSelect,
  placeholder = 'Enter venue address',
  showMap = false,
  className = '',
  apiKey
}) => {
  const mapRef = useRef<any>(null);
  const markerRef = useRef<any>(null);
  const placePickerRef = useRef<any>(null);

  // Initialize Google Maps API loader once globally
  useEffect(() => {
    console.log('GooglePlacePicker mounted with API Key:', apiKey ? 'Key provided' : 'No key provided');
    if (!apiKey) {
      console.error('Google Maps API key is missing! Check your .env.local file');
      return;
    }

    // Check if loader already exists
    if (!document.querySelector('gmpx-api-loader')) {
      const loader = document.createElement('gmpx-api-loader');
      loader.setAttribute('api-key', apiKey);
      loader.setAttribute('solution-channel', 'GMP_GE_mapsandplacesautocomplete_v2');
      document.body.appendChild(loader);
      console.log('Google Maps API loader added');
    } else {
      console.log('Google Maps API loader already exists');
    }
  }, [apiKey]);

  useEffect(() => {
    const handlePlaceChange = (event: any) => {
      const place = event.target.value as PlaceDetails;

      if (!place || !place.formattedAddress) {
        return;
      }

      // Parse address components
      let street = '';
      let city = '';
      let state = '';
      let zip = '';

      // Extract from formatted address if components not available
      const addressParts = place.formattedAddress.split(',');
      if (addressParts.length >= 3) {
        street = addressParts[0].trim();
        city = addressParts[1].trim();
        const stateZip = addressParts[2].trim().split(' ');
        state = stateZip[0] || '';
        zip = stateZip[1] || '';
      }

      // Get coordinates
      const lat = place.location?.lat() || 0;
      const lng = place.location?.lng() || 0;

      // Update map if showing
      if (showMap && mapRef.current && markerRef.current && place.location) {
        const map = mapRef.current;
        const marker = markerRef.current;

        map.center = place.location;
        map.zoom = 17;
        marker.position = place.location;
      }

      // Send parsed data to parent
      onPlaceSelect({
        name: place.displayName || '',
        address: street,
        city,
        state,
        zip,
        lat,
        lng
      });
    };

    // Add event listener
    const picker = placePickerRef.current;
    if (picker) {
      picker.addEventListener('gmpx-placechange', handlePlaceChange);

      return () => {
        picker.removeEventListener('gmpx-placechange', handlePlaceChange);
      };
    }
  }, [onPlaceSelect, showMap]);

  return (
    <div className={className}>
      <div className="space-y-4">
        {/* @ts-ignore */}
        <gmpx-place-picker
          ref={placePickerRef}
          placeholder={placeholder}
          className="w-full"
          style={{
            width: '100%',
            minHeight: '44px',
            display: 'block'
          }}
        />

        {showMap && (
          <div style={{ height: '300px', border: '2px solid #234F1E' }}>
            {/* @ts-ignore */}
            <gmp-map
              ref={mapRef}
              center="39.8283,-98.5795"
              zoom="4"
              map-id="DEMO_MAP_ID"
              style={{ height: '100%', width: '100%' }}
            >
              {/* @ts-ignore */}
              <gmp-advanced-marker ref={markerRef} />
              {/* @ts-ignore */}
            </gmp-map>
          </div>
        )}
      </div>
    </div>
  );
};

export default GooglePlacePicker;