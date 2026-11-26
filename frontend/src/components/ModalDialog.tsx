'use client';

import React, { useEffect, useId, useRef } from 'react';
import { X } from 'lucide-react';

interface ModalDialogProps {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
  maxWidthClass?: string;
}

export function ModalDialog({
  open,
  onClose,
  title,
  children,
  maxWidthClass = 'max-w-lg',
}: ModalDialogProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const titleId = useId();

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;

    if (open && !dialog.open) {
      dialog.showModal();
    } else if (!open && dialog.open) {
      dialog.close();
    }
  }, [open]);

  const handleClose = () => {
    const dialog = dialogRef.current;
    if (dialog) {
      dialog.returnValue = '';
    }
    onClose();
  };

  return (
    <dialog
      ref={dialogRef}
      aria-labelledby={titleId}
      onClose={handleClose}
      onCancel={(e) => {
        e.preventDefault();
        handleClose();
      }}
      className={`m-auto w-[calc(100%-2rem)] ${maxWidthClass} rounded-xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl`}
    >
      <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
        <h1 id={titleId} className="text-base font-semibold text-slate-900">
          {title}
        </h1>
        <button
          type="button"
          onClick={handleClose}
          aria-label="Cerrar diálogo"
          className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
        >
          <X className="h-4 w-4" />
        </button>
      </div>
      <div className="p-5">{children}</div>
    </dialog>
  );
}
