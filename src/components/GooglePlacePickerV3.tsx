import React, { useEffect, useRef } from 'react';

// Declare Web Components for TypeScript
declare global {
  namespace JSX {
    interface IntrinsicElements {
      'gmpx-api-loader': any;
      'gmp-map': any;
      'gmp-advanced-marker': any;
    }
  }
}

interface GooglePlacePickerV3Props {
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

const GooglePlacePickerV3: React.FC<GooglePlacePickerV3Props> = ({
  onPlaceSelect,
  placeholder = 'Enter venue address',
  showMap = false,
  className = '',
  apiKey
}) => {
  const inputRef = useRef<HTMLInputElement>(null);
  const mapRef = useRef<any>(null);
  const markerRef = useRef<any>(null);

  useEffect(() => {
    let autocomplete: any = null;

    const initializeAutocomplete = () => {
      // Wait for Google Maps API to be available
      if (!(window as any).google?.maps?.places?.Autocomplete) {
        console.log('Waiting for Google Maps Places API to load...');
        setTimeout(initializeAutocomplete, 100);
        return;
      }

      if (!inputRef.current) return;

      try {
        // Create autocomplete using standard Google Maps API
        autocomplete = new (window as any).google.maps.places.Autocomplete(inputRef.current, {
          fields: ['address_components', 'geometry', 'name'],
          types: ['address']
        });

        // Listen for place selection
        autocomplete.addListener('place_changed', () => {
          const place = autocomplete.getPlace();
          console.log('Place selected (Web Components):', place);

          if (!place.geometry || !place.geometry.location) {
            console.error('No geometry data from selected place');
            return;
          }

          // Parse address components
          let streetNumber = '';
          let route = '';
          let city = '';
          let state = '';
          let zip = '';

          for (const component of place.address_components || []) {
            const type = component.types[0];
            switch(type) {
              case 'street_number':
                streetNumber = component.short_name;
                break;
              case 'route':
                route = component.long_name;
                break;
              case 'locality':
                city = component.long_name;
                break;
              case 'administrative_area_level_1':
                state = component.short_name;
                break;
              case 'postal_code':
                zip = component.short_name;
                break;
            }
          }

          const address = streetNumber && route ? `${streetNumber} ${route}` : route;
          const lat = place.geometry.location.lat();
          const lng = place.geometry.location.lng();

          // Update map using Web Components approach (like Google's example)
          if (showMap && mapRef.current && markerRef.current) {
            console.log('Updating map with Web Components approach to:', lat, lng);

            // Set map center directly on Web Component
            mapRef.current.center = place.geometry.location;

            // Set marker position directly on Web Component
            markerRef.current.position = place.geometry.location;
          }

          // Send data to parent
          onPlaceSelect({
            name: place.name || address,
            address,
            city,
            state,
            zip,
            lat,
            lng
          });
        });

        console.log('Autocomplete initialized successfully');

      } catch (error) {
        console.error('Error initializing autocomplete:', error);
      }
    };

    initializeAutocomplete();

    // Cleanup
    return () => {
      if (autocomplete && (window as any).google?.maps?.event) {
        (window as any).google.maps.event.clearInstanceListeners(autocomplete);
      }
    };
  }, [apiKey, showMap, onPlaceSelect]);

  return (
    <div className={className}>
      {/* @ts-ignore */}
      <gmpx-api-loader
        key={apiKey}
        api-key={apiKey}
        solution-channel="GMP_GE_mapsandplacesautocomplete_v2"
      />

      <input
        ref={inputRef}
        type="text"
        placeholder={placeholder}
        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
      />

      {showMap && (
        <div className="mt-4" style={{ height: '300px', border: '2px solid #234F1E' }}>
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

      <div className="text-xs text-gray-600 mt-2">
        <span className="font-semibold">Auto-filled:</span> City, State, ZIP from selected address
      </div>
    </div>
  );
};

export default GooglePlacePickerV3;