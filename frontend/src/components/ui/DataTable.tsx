import React, { useMemo, useState } from 'react';

/**
 * The ONE table treatment for the staff app.
 *
 * Chosen 2026-09-06 from the most common shape already in the codebase:
 * `thead bg-gray-50`, `th px-4 py-3 text-left text-xs font-medium
 * text-brand-primary uppercase tracking-wide`, `tbody divide-y
 * divide-gray-200`, `tr hover:bg-gray-50`, `td px-4 py-3 text-sm`. The
 * table sits inside an `overflow-x-auto` container so a wide table scrolls
 * itself and the page never scrolls sideways. See
 * docs/ui-consistency-inventory-2026-09.md for the tally.
 *
 * Deliberately thin: no library, no virtualisation, no server-side paging.
 * Sorting is optional and client-side. Row selection, expansion and
 * pagination stay in the page — they were never consistent enough to lift.
 *
 * `uiConsistency.test.ts` fails if a staff page renders a raw `<table`
 * outside this component.
 */

export type DataTableAlign = 'left' | 'center' | 'right';

export interface DataTableColumn<Row> {
  /** Unique key; also the default field read from the row when `render` is absent. */
  key: string;
  header: React.ReactNode;
  render?: (row: Row, index: number) => React.ReactNode;
  align?: DataTableAlign;
  /** Any CSS width (e.g. `'8rem'`, `'20%'`). */
  width?: string;
  /** Enables the click-to-sort header. Uses `sortValue` when given, else the row field. */
  sortable?: boolean;
  sortValue?: (row: Row) => string | number | null | undefined;
  /** Extra classes for every cell in this column (header and body). */
  className?: string;
  /**
   * Marks the actions column: right-aligned, nowrap, and clicks inside it do
   * not trigger `onRowClick`.
   */
  actions?: boolean;
}

export interface DataTableEmptyState {
  text: React.ReactNode;
  action?: React.ReactNode;
}

export interface DataTableProps<Row> {
  columns: DataTableColumn<Row>[];
  rows: Row[];
  rowKey: (row: Row, index: number) => string | number;
  emptyState?: DataTableEmptyState | string;
  onRowClick?: (row: Row) => void;
  /** Initial sort when a sortable column is present. */
  defaultSort?: { key: string; dir: 'asc' | 'desc' };
  /** Extra classes on a body row (e.g. to highlight a status). */
  rowClassName?: (row: Row, index: number) => string;
  /** Rendered after the table body rows, inside <tbody>. Use for totals. */
  footer?: React.ReactNode;
  /**
   * Row expansion: return content for a row and it is rendered in a
   * full-width row DIRECTLY UNDER that row (a detail drawer, a per-email
   * report). Return null/undefined for rows that are not expanded.
   */
  renderExpandedRow?: (row: Row, index: number) => React.ReactNode;
  /** Optional caption for screen readers. */
  caption?: string;
  /** Height cap; the header stays visible while the body scrolls. */
  maxHeight?: string;
  className?: string;
  /** For tests and analytics. */
  'data-testid'?: string;
}

const ALIGN: Record<DataTableAlign, string> = {
  left: 'text-left',
  center: 'text-center',
  right: 'text-right',
};

export const DATA_TABLE_CLASSES = {
  wrapper: 'overflow-x-auto rounded-lg border border-gray-200 bg-white',
  table: 'min-w-full',
  thead: 'bg-gray-50 sticky top-0 z-10',
  th: 'px-4 py-3 text-xs font-medium text-brand-primary uppercase tracking-wide',
  tbody: 'divide-y divide-gray-200',
  tr: 'hover:bg-gray-50',
  trClickable: 'cursor-pointer',
  td: 'px-4 py-3 text-sm text-gray-900',
  empty: 'px-4 py-12 text-center text-sm text-gray-500',
};

function defaultSortValue<Row>(row: Row, key: string): string | number | null | undefined {
  const v = (row as unknown as Record<string, unknown>)[key];
  if (v == null) return v as null | undefined;
  if (typeof v === 'number' || typeof v === 'string') return v;
  return String(v);
}

function compare(a: unknown, b: unknown): number {
  if (typeof a === 'number' && typeof b === 'number') return a - b;
  return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

function DataTable<Row>({
  columns,
  rows,
  rowKey,
  emptyState,
  onRowClick,
  defaultSort,
  rowClassName,
  footer,
  renderExpandedRow,
  caption,
  maxHeight,
  className = '',
  'data-testid': testId,
}: DataTableProps<Row>) {
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(
    defaultSort ?? null
  );

  const sorted = useMemo(() => {
    if (!sort) return rows;
    const col = columns.find((c) => c.key === sort.key);
    if (!col || !col.sortable) return rows;
    const value = col.sortValue ?? ((r: Row) => defaultSortValue(r, col.key));
    const copy = rows.map((row, index) => ({ row, index }));
    copy.sort((x, y) => {
      const a = value(x.row);
      const b = value(y.row);
      // Nulls sort last in BOTH directions — an empty cell is not a smallest value.
      if (a == null || b == null) {
        if (a == null && b == null) return x.index - y.index;
        return a == null ? 1 : -1;
      }
      const c = compare(a, b);
      if (c !== 0) return sort.dir === 'asc' ? c : -c;
      return x.index - y.index; // stable
    });
    return copy.map((c) => c.row);
  }, [rows, columns, sort]);

  const toggleSort = (key: string) => {
    setSort((prev) => {
      if (!prev || prev.key !== key) return { key, dir: 'asc' };
      if (prev.dir === 'asc') return { key, dir: 'desc' };
      return null;
    });
  };

  const empty: DataTableEmptyState | null =
    typeof emptyState === 'string' ? { text: emptyState } : emptyState ?? null;

  const wrapperStyle = maxHeight ? { maxHeight, overflowY: 'auto' as const } : undefined;

  return (
    <div
      className={`${DATA_TABLE_CLASSES.wrapper} ${className}`.trim()}
      style={wrapperStyle}
      data-testid={testId}
    >
      <table className={DATA_TABLE_CLASSES.table}>
        {caption && <caption className="sr-only">{caption}</caption>}
        <thead className={DATA_TABLE_CLASSES.thead}>
          <tr>
            {columns.map((col) => {
              const align = ALIGN[col.align ?? (col.actions ? 'right' : 'left')];
              const active = sort?.key === col.key;
              const ariaSort = active ? (sort!.dir === 'asc' ? 'ascending' : 'descending') : undefined;
              return (
                <th
                  key={col.key}
                  scope="col"
                  style={col.width ? { width: col.width } : undefined}
                  aria-sort={col.sortable ? ariaSort ?? 'none' : undefined}
                  className={`${DATA_TABLE_CLASSES.th} ${align} ${col.actions ? 'whitespace-nowrap' : ''} ${col.className ?? ''}`.trim()}
                >
                  {col.sortable ? (
                    <button
                      type="button"
                      onClick={() => toggleSort(col.key)}
                      className="inline-flex items-center gap-1 uppercase tracking-wide hover:underline focus:outline-none focus:underline"
                    >
                      {col.header}
                      <span aria-hidden="true" className={active ? '' : 'text-gray-400'}>
                        {active ? (sort!.dir === 'asc' ? '▲' : '▼') : '▾'}
                      </span>
                    </button>
                  ) : (
                    col.header
                  )}
                </th>
              );
            })}
          </tr>
        </thead>
        <tbody className={DATA_TABLE_CLASSES.tbody}>
          {sorted.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className={DATA_TABLE_CLASSES.empty}>
                <div>{empty?.text ?? 'Nothing to show.'}</div>
                {empty?.action && <div className="mt-3">{empty.action}</div>}
              </td>
            </tr>
          ) : (
            sorted.map((row, index) => {
              const clickable = Boolean(onRowClick);
              const extra = rowClassName ? rowClassName(row, index) : '';
              const key = rowKey(row, index);
              const expanded = renderExpandedRow ? renderExpandedRow(row, index) : null;
              return (
                <React.Fragment key={key}>
                <tr
                  onClick={clickable ? () => onRowClick!(row) : undefined}
                  className={`${DATA_TABLE_CLASSES.tr} ${clickable ? DATA_TABLE_CLASSES.trClickable : ''} ${extra}`.trim()}
                >
                  {columns.map((col) => {
                    const align = ALIGN[col.align ?? (col.actions ? 'right' : 'left')];
                    const content = col.render
                      ? col.render(row, index)
                      : ((row as unknown as Record<string, React.ReactNode>)[col.key] ?? null);
                    return (
                      <td
                        key={col.key}
                        onClick={col.actions && clickable ? (e) => e.stopPropagation() : undefined}
                        className={`${DATA_TABLE_CLASSES.td} ${align} ${col.actions ? 'whitespace-nowrap' : ''} ${col.className ?? ''}`.trim()}
                      >
                        {content}
                      </td>
                    );
                  })}
                </tr>
                {expanded != null && expanded !== false && (
                  <tr className="bg-gray-50" data-testid="expanded-row">
                    <td colSpan={columns.length} className="px-4 py-4">
                      {expanded}
                    </td>
                  </tr>
                )}
                </React.Fragment>
              );
            })
          )}
          {footer}
        </tbody>
      </table>
    </div>
  );
}

export default DataTable;
