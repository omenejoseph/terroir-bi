/**
 * English catalog. This file is the canonical shape — every other locale must
 * provide the same keys (enforced via the `Messages` type). Use {placeholders}
 * for interpolation, resolved by t() in the i18n context.
 *
 * No UI component should contain a raw user-facing string; add it here instead.
 */
export const en = {
  common: {
    signOut: "Sign out",
    search: "Search…",
    loading: "Loading…",
    retry: "Retry",
    forSale: "For sale",
    comingSoon: "Coming soon",
    status: {
      active: "Active",
      inactive: "Inactive",
    },
    language: "Language",
  },
  nav: {
    dashboard: "Dashboard",
    inventory: "Inventory",
    customers: "Customers",
  },
  tenant: {
    select: "Select tenant",
    switcherLabel: "Active tenant",
  },
  login: {
    title: "Sign in to {app}",
    subtitle: "Use your account email and password.",
    emailLabel: "Email",
    emailPlaceholder: "you@example.com",
    passwordLabel: "Password",
    passwordPlaceholder: "••••••••",
    submit: "Sign in",
    errorGeneric: "Unable to sign in. Check your connection and try again.",
  },
  dashboard: {
    welcome: "Welcome back, {name}",
    noTenant: "No active tenant",
    statInventory: "Inventory items",
    statRoles: "Your roles",
    statTenants: "Tenants",
    none: "—",
    sessionTitle: "Active session",
    sessionSubtitle: "What this device is authenticated as.",
  },
  inventory: {
    title: "Inventory",
    subtitleCount: "{count} items",
    subtitleDefault: "Items in the active tenant",
    searchPlaceholder: "Search by name or SKU…",
    empty: "No items found.",
    errorForbidden: "You don't have permission to view inventory for this tenant.",
    errorGeneric: "Failed to load inventory.",
    colName: "Name",
    colSku: "SKU",
    colCategory: "Category",
    colStock: "Stock",
    colPrice: "Price",
    colStatus: "Status",
  },
  customers: {
    title: "Customers",
    subtitle: "Customers & pricing.",
    comingSoonTitle: "Coming soon",
    comingSoonDesc:
      "Follow the Inventory module pattern to build this out against the /customers and /pricing-tiers endpoints.",
  },
} as const;

/** Widen string literals to `string` so other locales supply their own text. */
type DeepString<T> = {
  [K in keyof T]: T[K] extends string ? string : DeepString<T[K]>;
};

/** The canonical message shape all locales must satisfy (same keys, any string). */
export type Messages = DeepString<typeof en>;