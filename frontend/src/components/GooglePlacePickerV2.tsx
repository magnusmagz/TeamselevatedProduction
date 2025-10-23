import React, { useEffect, useRef } from 'react';

interface PlaceDetails {
  name?: string;
  address_components?: google.maps.GeocoderAddressComponent[];
  geometry?: {
    location?: google.maps.LatLng;
  };
}

interface GooglePlacePickerV2Props {
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

declare global {
  interface Window {
    google: any;
    initGoogleMaps: () => void;
  }
}

const GooglePlacePickerV2: React.FC<GooglePlacePickerV2Props> = ({
  onPlaceSelect,
  placeholder = 'Enter venue address',
  showMap = false,
  className = '',
  apiKey
}) => {
  const inputRef = useRef<HTMLInputElement>(null);
  const mapRef = useRef<HTMLDivElement>(null);
  const mapInstanceRef = useRef<any>(null);
  const markerRef = useRef<any>(null);
  const autocompleteRef = useRef<any>(null);

  useEffect(() => {
    // Load Google Maps script if not already loaded
    if (!window.google) {
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places&callback=initGoogleMaps`;
      script.async = true;
      script.defer = true;

      window.initGoogleMaps = () => {
        initAutocomplete();
      };

      document.head.appendChild(script);
    } else {
      initAutocomplete();
    }

    function initAutocomplete() {
      if (!inputRef.current || !window.google) return;

      // Initialize autocomplete
      autocompleteRef.current = new window.google.maps.places.Autocomplete(inputRef.current, {
        fields: ['address_components', 'geometry', 'name'],
        types: ['address']
      });

      // Initialize map if needed
      if (showMap && mapRef.current) {
        mapInstanceRef.current = new window.google.maps.Map(mapRef.current, {
          center: { lat: 39.8283, lng: -98.5795 },
          zoom: 4,
          mapTypeControl: false,
          fullscreenControl: false,
          streetViewControl: false
        });

        markerRef.current = new window.google.maps.Marker({
          map: mapInstanceRef.current,
          anchorPoint: new window.google.maps.Point(0, -29)
        });
      }

      // Add listener for place selection
      autocompleteRef.current.addListener('place_changed', () => {
        const place = autocompleteRef.current.getPlace();
        console.log('Place changed event fired:', place);

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
        let country = '';

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
            case 'country':
              country = component.long_name;
              break;
          }
        }

        const address = streetNumber && route ? `${streetNumber} ${route}` : route;
        const lat = place.geometry.location?.lat() || 0;
        const lng = place.geometry.location?.lng() || 0;

        // Create a full address string for Google Maps URL
        const fullAddress = [
          address,
          city,
          state ? `${state} ${zip}`.trim() : zip
        ].filter(Boolean).join(', ');

        console.log('Place selected:', {
          name: place.name,
          lat,
          lng,
          showMap,
          hasMapInstance: !!mapInstanceRef.current,
          hasMarker: !!markerRef.current,
          hasLocation: !!place.geometry.location
        });

        // Update map if showing
        if (showMap && mapInstanceRef.current && markerRef.current && place.geometry.location) {
          console.log('Updating map to:', lat, lng);

          // Try different approaches to ensure map updates
          const location = place.geometry.location;

          // Set center and zoom - 15 is good for street level, 17-18 for building level
          mapInstanceRef.current.setCenter(location);
          mapInstanceRef.current.setZoom(15);

          // Update marker
          markerRef.current.setPosition(location);
          markerRef.current.setVisible(true);

          // Force a refresh by panning to location
          setTimeout(() => {
            mapInstanceRef.current.panTo(location);
          }, 100);

          console.log('Map updated successfully');
        } else {
          console.log('Map update skipped:', {
            showMap,
            hasMapInstance: !!mapInstanceRef.current,
            hasMarker: !!markerRef.current,
            hasLocation: !!place.geometry.location
          });
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
    }

    // Cleanup
    return () => {
      if (autocompleteRef.current) {
        window.google.maps.event.clearInstanceListeners(autocompleteRef.current);
      }
    };
  }, [apiKey, showMap, onPlaceSelect]);

  return (
    <div className={className}>
      <input
        ref={inputRef}
        type="text"
        placeholder={placeholder}
        className="w-full bg-white text-forest-800 border-2 border-forest-800 px-4 py-2 focus:outline-none focus:border-forest-600"
      />

      {showMap && (
        <div
          ref={mapRef}
          className="mt-4"
          style={{ height: '300px', border: '2px solid #234F1E' }}
        />
      )}

      <div className="grid grid-cols-3 gap-2 mt-2">
        <div className="text-xs text-gray-600">
          <span className="font-semibold">Auto-filled:</span> City, State, ZIP
        </div>
      </div>
    </div>
  );
};

export default GooglePlacePickerV2;