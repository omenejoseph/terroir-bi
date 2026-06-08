import type { Messages } from "@/i18n/messages/en";

/** Croatian catalog (default locale). Must match the shape of `en`. */
export const hr: Messages = {
  common: {
    signOut: "Odjava",
    search: "Pretraži…",
    loading: "Učitavanje…",
    retry: "Pokušaj ponovno",
    forSale: "Na prodaju",
    comingSoon: "Uskoro",
    status: {
      active: "Aktivno",
      inactive: "Neaktivno",
    },
    language: "Jezik",
  },
  nav: {
    dashboard: "Nadzorna ploča",
    inventory: "Zaliha",
    customers: "Kupci",
  },
  tenant: {
    select: "Odaberi organizaciju",
    switcherLabel: "Aktivna organizacija",
  },
  login: {
    title: "Prijava u {app}",
    subtitle: "Koristite e-poštu i lozinku svog računa.",
    emailLabel: "E-pošta",
    emailPlaceholder: "vi@primjer.com",
    passwordLabel: "Lozinka",
    passwordPlaceholder: "••••••••",
    submit: "Prijava",
    errorGeneric: "Prijava nije uspjela. Provjerite vezu i pokušajte ponovno.",
  },
  dashboard: {
    welcome: "Dobrodošli natrag, {name}",
    noTenant: "Nema aktivne organizacije",
    statInventory: "Stavke zalihe",
    statRoles: "Vaše uloge",
    statTenants: "Organizacije",
    none: "—",
    sessionTitle: "Aktivna sesija",
    sessionSubtitle: "Kao tko je ovaj uređaj prijavljen.",
  },
  inventory: {
    title: "Zaliha",
    subtitleCount: "{count} stavki",
    subtitleDefault: "Stavke u aktivnoj organizaciji",
    searchPlaceholder: "Pretraži po nazivu ili SKU…",
    empty: "Nema pronađenih stavki.",
    errorForbidden: "Nemate dopuštenje za pregled zalihe ove organizacije.",
    errorGeneric: "Učitavanje zalihe nije uspjelo.",
    colName: "Naziv",
    colSku: "SKU",
    colCategory: "Kategorija",
    colStock: "Zaliha",
    colPrice: "Cijena",
    colStatus: "Status",
  },
  customers: {
    title: "Kupci",
    subtitle: "Kupci i cijene.",
    comingSoonTitle: "Uskoro",
    comingSoonDesc:
      "Slijedite obrazac modula Zaliha za izradu ovoga prema /customers i /pricing-tiers krajnjim točkama.",
  },
};