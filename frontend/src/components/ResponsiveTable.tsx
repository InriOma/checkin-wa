import React, { useId } from 'react';

interface ResponsiveTableProps {
  caption: string;
  headers: string[];
  children: React.ReactNode;
}

export function ResponsiveTable({ caption, headers, children }: ResponsiveTableProps) {
  const captionId = useId();

  return (
    <div
      tabIndex={0}
      role="region"
      aria-labelledby={captionId}
      className="table-wrapper rounded-xl border border-slate-200 bg-white shadow-xs"
    >
      <table className="responsive-table text-left text-sm">
        <caption id={captionId} className="sr-only">
          {caption}
        </caption>
        <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
          <tr>
            {headers.map((header) => (
              <th key={header} scope="col" className="border-b border-slate-200 px-4 py-3 whitespace-nowrap">
                {header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 text-slate-700">{children}</tbody>
      </table>
    </div>
  );
}
