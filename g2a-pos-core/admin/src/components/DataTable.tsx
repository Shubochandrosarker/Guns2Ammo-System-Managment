import { type ReactNode, useMemo, useState } from 'react';

export interface Column<T> {
  key: string;
  label: string;
  render?: (row: T) => ReactNode;
  width?: string;
  align?: 'left' | 'right' | 'center';
  sortable?: boolean;
  sortValue?: (row: T) => string | number | null | undefined;
}

type Direction = 'asc' | 'desc';

export default function DataTable<T extends object>({
  rows,
  columns,
  empty = 'No records.',
  loading = false,
  rowKey,
  caption,
  onRowClick,
}: {
  rows: T[];
  columns: Column<T>[];
  empty?: string;
  loading?: boolean;
  rowKey: (row: T, i: number) => string | number;
  caption?: string;
  onRowClick?: (row: T) => void;
}) {
  const [sort, setSort] = useState<{ key: string; direction: Direction } | null>(null);
  const sortedRows = useMemo(() => {
    if (!sort) return rows;
    const column = columns.find((item) => item.key === sort.key);
    if (!column) return rows;
    return [...rows].sort((a, b) => {
      const av = column.sortValue ? column.sortValue(a) : (a as Record<string, unknown>)[column.key];
      const bv = column.sortValue ? column.sortValue(b) : (b as Record<string, unknown>)[column.key];
      const result = typeof av === 'number' && typeof bv === 'number'
        ? av - bv
        : String(av ?? '').localeCompare(String(bv ?? ''), undefined, { numeric: true, sensitivity: 'base' });
      return sort.direction === 'asc' ? result : -result;
    });
  }, [rows, columns, sort]);

  const toggleSort = (key: string) => {
    setSort((value) => value?.key === key
      ? { key, direction: value.direction === 'asc' ? 'desc' : 'asc' }
      : { key, direction: 'asc' });
  };

  return (
    <div className="card w-full overflow-hidden">
      <div className="flex min-h-12 items-center justify-between border-b border-zinc-100 px-4 dark:border-zinc-800">
        <div className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{caption || 'Records'}</div>
        <div className="badge-zinc">{loading ? 'Loading' : `${rows.length} ${rows.length === 1 ? 'record' : 'records'}`}</div>
      </div>
      <div className="w-full overflow-x-auto">
        <table className="w-full min-w-full text-sm">
          <thead className="sticky top-0 z-10 bg-zinc-50/95 backdrop-blur dark:bg-zinc-900/95">
            <tr>
              {columns.map((column) => (
                <th key={column.key} className="table-head px-4 py-3" style={{ width: column.width, textAlign: column.align || 'left' }} aria-sort={sort?.key === column.key ? (sort.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                  {column.sortable !== false ? (
                    <button type="button" className="inline-flex items-center gap-1.5 hover:text-zinc-900 dark:hover:text-white" onClick={() => toggleSort(column.key)}>
                      {column.label}<span className="text-[10px] text-zinc-400" aria-hidden="true">{sort?.key === column.key ? (sort.direction === 'asc' ? '▲' : '▼') : '↕'}</span>
                    </button>
                  ) : column.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading && Array.from({ length: 4 }).map((_, index) => (
              <tr key={`skeleton-${index}`} aria-hidden="true">{columns.map((column) => <td key={column.key} className="table-cell"><div className="h-4 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800" /></td>)}</tr>
            ))}
            {!loading && rows.length === 0 && (
              <tr><td colSpan={columns.length} className="px-6 py-14 text-center"><div className="text-2xl" aria-hidden="true">◇</div><div className="mt-2 font-medium text-zinc-700 dark:text-zinc-200">{empty}</div><div className="mt-1 text-xs text-zinc-500">Try changing filters or create the first record.</div></td></tr>
            )}
            {!loading && sortedRows.map((row, index) => (
              <tr
                key={rowKey(row, index)}
                tabIndex={onRowClick ? 0 : undefined}
                onClick={() => onRowClick?.(row)}
                onKeyDown={(event) => { if (onRowClick && (event.key === 'Enter' || event.key === ' ')) onRowClick(row); }}
                className={`transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-900/50 ${onRowClick ? 'cursor-pointer focus:bg-brand/5 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-brand' : ''}`}
              >
                {columns.map((column) => <td key={column.key} className="table-cell" style={{ textAlign: column.align || 'left' }}>{column.render ? column.render(row) : ((row as Record<string, unknown>)[column.key] as ReactNode) ?? '—'}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
