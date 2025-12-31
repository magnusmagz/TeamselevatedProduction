import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';

interface CartItem {
  id: string; // Unique ID for cart item
  programId: number;
  programName: string;
  athleteData: {
    firstName: string;
    lastName: string;
    birthday: string;
    gender: string;
    grade?: string;
  };
  guardianData: {
    firstName: string;
    lastName: string;
    email: string;
    phone: string;
  };
  formData: Record<string, any>;
  basePrice: number;
  discountAmount: number;
  finalPrice: number;
  discountReason?: string;
}

interface RegistrationCartContextType {
  items: CartItem[];
  addItem: (item: Omit<CartItem, 'id'>) => void;
  removeItem: (id: string) => void;
  updateItem: (id: string, updates: Partial<CartItem>) => void;
  clearCart: () => void;
  getTotal: () => number;
  getTotalDiscount: () => number;
  itemCount: number;
}

const RegistrationCartContext = createContext<RegistrationCartContextType | undefined>(undefined);

export const useRegistrationCart = () => {
  const context = useContext(RegistrationCartContext);
  if (!context) {
    throw new Error('useRegistrationCart must be used within a RegistrationCartProvider');
  }
  return context;
};

interface Props {
  children: ReactNode;
}

export const RegistrationCartProvider: React.FC<Props> = ({ children }) => {
  const [items, setItems] = useState<CartItem[]>(() => {
    // Load from localStorage on init
    const saved = localStorage.getItem('registrationCart');
    return saved ? JSON.parse(saved) : [];
  });

  // Persist to localStorage
  useEffect(() => {
    localStorage.setItem('registrationCart', JSON.stringify(items));
  }, [items]);

  const addItem = (item: Omit<CartItem, 'id'>) => {
    const newItem: CartItem = {
      ...item,
      id: `cart-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`
    };
    setItems(prev => [...prev, newItem]);
  };

  const removeItem = (id: string) => {
    setItems(prev => prev.filter(item => item.id !== id));
  };

  const updateItem = (id: string, updates: Partial<CartItem>) => {
    setItems(prev => prev.map(item =>
      item.id === id ? { ...item, ...updates } : item
    ));
  };

  const clearCart = () => {
    setItems([]);
    localStorage.removeItem('registrationCart');
  };

  const getTotal = () => {
    return items.reduce((sum, item) => sum + item.finalPrice, 0);
  };

  const getTotalDiscount = () => {
    return items.reduce((sum, item) => sum + item.discountAmount, 0);
  };

  return (
    <RegistrationCartContext.Provider value={{
      items,
      addItem,
      removeItem,
      updateItem,
      clearCart,
      getTotal,
      getTotalDiscount,
      itemCount: items.length
    }}>
      {children}
    </RegistrationCartContext.Provider>
  );
};
