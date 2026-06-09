/**
 * Frontend mirror of the backend's App\Authorization\RoleCapabilities map.
 * Used only to show/hide UI affordances — the API remains the real authority
 * (every gated route still enforces `can:*`, so a hidden button is defence in
 * depth, not the security boundary). Keep in sync with RoleCapabilities.php.
 */

const WILDCARD = "*";

/** Role value (TenantRole enum) → granted capabilities. */
const ROLE_CAPABILITIES: Record<string, string[]> = {
  ADMIN: [WILDCARD],
  TEAM: [
    "customers.view",
    "customers.manage",
    "pricing.view",
    "pricing.manage",
    "inventory.view",
    "inventory.manage",
    "orders.view",
    "orders.manage",
    "finance.view",
    "finance.manage",
    "suppliers.view",
    "suppliers.manage",
    "financials.view",
  ],
  CELLAR: [],
  ORDERS: ["orders.view", "orders.manage", "finance.view", "finance.manage", "financials.view"],
  MANAGER: ["finance.view", "finance.manage", "suppliers.view", "suppliers.manage", "financials.view"],
  SALES: ["finance.view", "financials.view"],
  HOSPITALITY: [],
  KITCHEN: [],
  EMPLOYEE: [],
  WINE_CLUB: [],
  INVENTORY: ["inventory.view", "inventory.manage"],
};

/** Does any of the held roles grant the given capability? */
export function rolesGrant(roles: string[], capability: string): boolean {
  return roles.some((role) => {
    const grants = ROLE_CAPABILITIES[role] ?? [];
    return grants.includes(WILDCARD) || grants.includes(capability);
  });
}
