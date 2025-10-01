import { ReactNode, CSSProperties, Ref } from 'react';

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

export {};