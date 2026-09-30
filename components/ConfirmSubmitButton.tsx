"use client";

import type { ReactNode, MouseEvent } from "react";

/**
 * Drop-in replacement for a plain <button type="submit"> inside any existing
 * <form action={serverAction}> — asks window.confirm() first and blocks the
 * submit if the user cancels. The only client-side piece; the surrounding
 * form/page stays a plain server component.
 */
export function ConfirmSubmitButton({
  confirmMessage,
  children,
  className,
  title,
  disabled,
}: {
  confirmMessage: string;
  children: ReactNode;
  className?: string;
  title?: string;
  disabled?: boolean;
}) {
  return (
    <button
      type="submit"
      title={title}
      className={className}
      disabled={disabled}
      onClick={(e: MouseEvent<HTMLButtonElement>) => {
        if (!window.confirm(confirmMessage)) {
          e.preventDefault();
        }
      }}
    >
      {children}
    </button>
  );
}
