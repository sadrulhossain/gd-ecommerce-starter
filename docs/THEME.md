# Theme baseline (inherited, unchanged in E0)

E0 changes no admin or portal styling. This file records what exists so that E11 restyles only the portal.

## Tokens (`packages/tokens`)
- `base.css`: breakpoints (`sm`, `md`, `lg`, `xl`) and fonts (`--font-sans`, `--font-mono`). Shared by both apps.
- `admin.css`: the admin theme. Colours `primary-50..900`, `canvas`, `surface`, `surface-muted`, `ink`, `ink-muted`, `line`, `line-strong`, `link`,
  `success`/`warning`/`danger`/`info` (plus `-solid` variants), `sidebar-glass/line/text`, `white`; radii `sm..2xl`; shadows `sm/md/lg`; text sizes `base`, `sm`.
  Light and dark values are set through `--tint-*` and the semantic variables.
- `portal.css`: the portal theme. Colours `brand-50..900`, `accent-300..600`, `night`, `night-soft`, `canvas`, `surface`, `ink`, `ink-muted`, `line`,
  `success`/`warning`/`danger`, `white`; `--font-display`; radii `sm..xl`; shadows `soft`, `lift`; text size `base`.
- Each theme resets `--color-*` so only its own palette exists.

## Admin components (`web/admin/src/components`) - stay as is
BilingualField, Brand, ConfirmDialog, CrudPage, DataTable, FormField, GalleryUploader, Icon, IconButton, ImagePanel, RichText, Splash, ThemeToggle,
Toast, ToggleCell. New admin modules are `CrudPage` configs (docs/PATTERNS.md); the site is English only, so BilingualField is not used for new fields.

## Portal components (`web/portal/components`) - restyled in E11
ContactForm, Footer, Header, HoursList, JsonLd, LanguageSwitcher, Media, MobileNav, NavDropdown, NotFoundContent, PricingPlans, SiteLogo,
SocialIcon, and `sections/*` (home and page sections).

## Rule
Admin theme: unchanged. Portal: change only `packages/tokens/portal.css` and the portal components, in E11.

## Text scale
Both themes override the whole Tailwind text scale (`--text-xs` ... `--text-7xl`) in their token file. Admin: every size one step smaller than the default scale (base 13px). Portal: every size two steps smaller (base 12px). Change a size there, not in a component.

## Portal shop design system (E11)
- Tokens added to `packages/tokens/portal.css`: `sale`, `sale-soft` (price cuts, AA on white and canvas), `star`, `star-empty` (ratings, decorative only). Everything else reuses the existing brand, accent, ink, line, radius and shadow tokens. The admin theme is untouched.
- Components in `web/portal/components/shop` (import from `components/shop`): `Button` / `ButtonLink` (primary, secondary, accent, ghost; sizes sm, md, lg), `Price` (compare-at and percent off), `Badge`, `Rating`, `StockStatus`, `QuantityStepper` (client), `ProductCard`, `EmptyState`, `Breadcrumbs`. Pure helpers (percent off, rating rounding, stock state, quantity clamp) are in `web/portal/lib/shop.ts`.
- Money is the API's `{amount, currency}` in minor units, formatted only with `formatMoney` from `@gd/api-client` (re-exported by `lib/shop.ts`); never format an amount by hand.
- Rules: logical utilities only (RTL safe), white text only on `brand-700` or darker, state is never colour alone (stock and sale show text), the card is one link (the product name stretched over the card).
- Preview: `/showcase` (dev only) shows every component; `web/portal/e2e/a11y.spec.ts` scans it with axe (zero violations).
