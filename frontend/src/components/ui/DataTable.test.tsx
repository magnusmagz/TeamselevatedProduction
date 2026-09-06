/* eslint-disable testing-library/no-node-access */
import React from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import DataTable, { DataTableColumn } from './DataTable';

interface Row {
  id: number;
  name: string;
  age: number | null;
}

const rows: Row[] = [
  { id: 1, name: 'Bravo', age: 12 },
  { id: 2, name: 'alpha', age: 9 },
  { id: 3, name: 'Charlie', age: null },
];

const columns: DataTableColumn<Row>[] = [
  { key: 'name', header: 'Name', sortable: true },
  { key: 'age', header: 'Age', sortable: true, align: 'right' },
  { key: 'actions', header: 'Actions', actions: true, render: (r) => <button>Edit {r.name}</button> },
];

const bodyNames = () =>
  screen
    .getAllByRole('row')
    .slice(1)
    .map((tr) => within(tr).getAllByRole('cell')[0].textContent);

describe('DataTable', () => {
  it('renders one header cell per column and one row per item', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />);
    expect(screen.getAllByRole('columnheader')).toHaveLength(3);
    expect(screen.getAllByRole('row')).toHaveLength(4); // header + 3
    expect(bodyNames()).toEqual(['Bravo', 'alpha', 'Charlie']);
  });

  it('reads the row field by key when no render is given, and uses render when it is', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />);
    expect(screen.getByText('12')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Edit Bravo' })).toBeInTheDocument();
  });

  it('renders the empty state text and action across all columns', () => {
    render(
      <DataTable
        columns={columns}
        rows={[]}
        rowKey={(r) => r.id}
        emptyState={{ text: 'No programs yet.', action: <button>+ Program</button> }}
      />
    );
    const cell = screen.getByRole('cell');
    expect(cell).toHaveAttribute('colspan', '3');
    expect(within(cell).getByText('No programs yet.')).toBeInTheDocument();
    expect(within(cell).getByRole('button', { name: '+ Program' })).toBeInTheDocument();
  });

  it('accepts a plain string empty state', () => {
    render(<DataTable columns={columns} rows={[]} rowKey={(r) => r.id} emptyState="Nothing here" />);
    expect(screen.getByText('Nothing here')).toBeInTheDocument();
  });

  it('toggles sort asc → desc → off on a sortable header, case-insensitively, nulls last', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />);
    const nameBtn = screen.getByRole('button', { name: /Name/ });
    fireEvent.click(nameBtn);
    expect(bodyNames()).toEqual(['alpha', 'Bravo', 'Charlie']);
    expect(screen.getAllByRole('columnheader')[0]).toHaveAttribute('aria-sort', 'ascending');
    fireEvent.click(nameBtn);
    expect(bodyNames()).toEqual(['Charlie', 'Bravo', 'alpha']);
    expect(screen.getAllByRole('columnheader')[0]).toHaveAttribute('aria-sort', 'descending');
    fireEvent.click(nameBtn);
    expect(bodyNames()).toEqual(['Bravo', 'alpha', 'Charlie']);
    expect(screen.getAllByRole('columnheader')[0]).toHaveAttribute('aria-sort', 'none');

    fireEvent.click(screen.getByRole('button', { name: /Age/ }));
    expect(bodyNames()).toEqual(['alpha', 'Bravo', 'Charlie']); // 9, 12, null
  });

  it('honours defaultSort', () => {
    render(
      <DataTable columns={columns} rows={rows} rowKey={(r) => r.id} defaultSort={{ key: 'age', dir: 'desc' }} />
    );
    expect(bodyNames()).toEqual(['Bravo', 'alpha', 'Charlie']);
  });

  it('does not render a sort button on a non-sortable column', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />);
    expect(screen.queryByRole('button', { name: /Actions/ })).not.toBeInTheDocument();
  });

  it('fires onRowClick for a row but not for a click inside the actions column', () => {
    const onRowClick = jest.fn();
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} onRowClick={onRowClick} />);
    fireEvent.click(screen.getByText('Bravo'));
    expect(onRowClick).toHaveBeenCalledWith(rows[0]);
    fireEvent.click(screen.getByRole('button', { name: 'Edit alpha' }));
    expect(onRowClick).toHaveBeenCalledTimes(1);
  });

  it('right-aligns and nowraps the actions column', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />);
    const th = screen.getAllByRole('columnheader')[2];
    expect(th.className).toContain('text-right');
    expect(th.className).toContain('whitespace-nowrap');
  });

  it('wraps the table in an overflow-x-auto container with a sticky header', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} data-testid="tbl" />);
    const wrapper = screen.getByTestId('tbl');
    expect(wrapper.className).toContain('overflow-x-auto');
    expect(wrapper.querySelector('thead')?.className).toContain('sticky');
    expect(wrapper.querySelector('thead')?.className).toContain('bg-gray-50');
    expect(wrapper.querySelector('tbody')?.className).toContain('divide-y');
  });

  it('applies rowClassName and renders the footer inside tbody', () => {
    render(
      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        rowClassName={(r) => (r.age == null ? 'opacity-50' : '')}
        footer={
          <tr>
            <td colSpan={3}>Total: 3</td>
          </tr>
        }
      />
    );
    const trs = screen.getAllByRole('row');
    expect(trs[3].className).toContain('opacity-50');
    expect(screen.getByText('Total: 3').closest('tbody')).not.toBeNull();
  });

  it('renders renderExpandedRow content directly under its row, and nothing for null', () => {
    render(
      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        renderExpandedRow={(r) => (r.id === 2 ? <div>Detail for alpha</div> : null)}
      />
    );
    const trs = screen.getAllByRole('row');
    expect(trs).toHaveLength(5); // header + 3 + 1 expanded
    expect(trs[2]).toHaveTextContent('alpha');
    expect(trs[3]).toHaveAttribute('data-testid', 'expanded-row');
    expect(within(trs[3]).getByText('Detail for alpha')).toBeInTheDocument();
    expect(within(trs[3]).getByRole('cell')).toHaveAttribute('colspan', '3');
  });

  it('stamps rowTestId on body rows', () => {
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} rowTestId={(r) => `row-${r.id}`} />);
    expect(screen.getByTestId('row-2')).toHaveTextContent('alpha');
  });
});
