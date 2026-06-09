"use client";

import { TENANT_ROLES } from "@/lib/types";
import { useTranslation } from "@/i18n/context";
import { cn } from "@/lib/utils";

/** Toggle chips for selecting one or more tenant roles. */
export function RoleCheckboxes({
  value,
  onChange,
}: {
  value: string[];
  onChange: (roles: string[]) => void;
}) {
  const { t } = useTranslation();

  function toggle(role: string) {
    onChange(value.includes(role) ? value.filter((r) => r !== role) : [...value, role]);
  }

  return (
    <div className="flex flex-wrap gap-2">
      {TENANT_ROLES.map((role) => {
        const active = value.includes(role);
        return (
          <button
            key={role}
            type="button"
            aria-pressed={active}
            onClick={() => toggle(role)}
            className={cn(
              "rounded-full border px-3 py-1.5 text-sm font-medium transition-colors",
              active
                ? "border-primary bg-primary/10 text-primary"
                : "border-border text-muted-foreground hover:bg-accent hover:text-foreground",
            )}
          >
            {t(`team.roles.${role}`)}
          </button>
        );
      })}
    </div>
  );
}