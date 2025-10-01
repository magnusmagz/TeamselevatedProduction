// Google Maps Configuration
export const GOOGLE_MAPS_CONFIG = {
  // TODO: Replace with your actual Google Maps API key
  // Get one at: https://console.cloud.google.com/
  apiKey: process.env.REACT_APP_GOOGLE_MAPS_API_KEY || 'YOUR_API_KEY_HERE',

  // Libraries to load
  libraries: ['places', 'geocoding'] as any,

  // Default center (US)
  defaultCenter: {
    lat: 39.8283,
    lng: -98.5795
  },

  // Autocomplete options
  autocompleteOptions: {
    componentRestrictions: { country: 'us' },
    fields: ['address_components', 'geometry', 'formatted_address', 'place_id'],
    types: ['address']
  }
};

// Note: For production, store API key in environment variable:
// Create a .env.local file in frontend folder with:
// REACT_APP_GOOGLE_MAPS_API_KEY=your_actual_key_here