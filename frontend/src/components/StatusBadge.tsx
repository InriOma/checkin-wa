import React from 'react';

const STATUS_STYLES: Record<string, string> = {
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  approved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  working: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  closed: 'bg-slate-100 text-slate-700 ring-slate-600/20',
  on_break: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  pending_verification: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  trial: 'bg-sky-50 text-sky-700 ring-sky-600/20',
  inactive: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  suspended: 'bg-rose-50 text-rose-700 ring-rose-600/20',
  rejected: 'bg-rose-50 text-rose-700 ring-rose-600/20',
};

const LABELS: Record<string, string> = {
  active: 'Activo',
  inactive: 'Inactivo',
  suspended: 'Suspendido',
  working: 'Trabajando',
  on_break: 'En descanso',
  closed: 'Cerrado',
  pending: 'Pendiente',
  approved: 'Aprobado',
  rejected: 'Rechazado',
  pending_verification: 'Por verificar',
  late: 'Retardo',
  absence: 'Falta',
  early_departure: 'Salida anticipada',
  incomplete_shift: 'Jornada incompleta',
  excess_break: 'Exceso descanso',
  outside_geofence: 'Fuera de geocerca',
};

export function StatusBadge({ value }: { value: string }) {
  const style = STATUS_STYLES[value] ?? 'bg-slate-100 text-slate-700 ring-slate-500/20';
  const label = LABELS[value] ?? value;

  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${style}`}
    >
      {label}
    </span>
  );
}
