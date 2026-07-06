import { render, screen, fireEvent } from '@testing-library/react';
import VenuePicker from './VenuePicker';
import { Venue } from '../types';

const venues: Venue[] = [
  { id: 1, name: 'North Park', city: 'Austin', state: 'TX' },
  { id: 2, name: 'South Field' },
];

describe('VenuePicker', () => {
  it('renders a "No facility" option plus every venue (with city/state when present)', () => {
    render(<VenuePicker venues={venues} value={undefined} onChange={() => {}} />);
    expect(screen.getByRole('option', { name: /No facility/i })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'North Park — Austin, TX' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'South Field' })).toBeInTheDocument();
  });

  it('emits the numeric venue id on change, and undefined for "No facility"', () => {
    const onChange = jest.fn();
    render(<VenuePicker venues={venues} value={1} onChange={onChange} />);
    const select = screen.getByRole('combobox');
    fireEvent.change(select, { target: { value: '2' } });
    expect(onChange).toHaveBeenCalledWith(2);
    fireEvent.change(select, { target: { value: '' } });
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  it('omits the "No facility" option when allowNone is false', () => {
    render(<VenuePicker venues={venues} value={1} onChange={() => {}} allowNone={false} />);
    expect(screen.queryByRole('option', { name: /No facility/i })).not.toBeInTheDocument();
  });
});
