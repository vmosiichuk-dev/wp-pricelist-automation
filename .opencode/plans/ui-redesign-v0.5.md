# Stock Sync v0.5.0 UI Redesign — Implementation Plan

## Overview
Redesign the Stock Sync WordPress plugin admin UI to match the WooCommerce Marketing tab aesthetic: centered narrow layout, rounded cards, gradient header panel, unified tabs, pill badges, sticky action bars, and toast notifications. Build `dist/stock-sync-v0.5.zip` without touching existing `/dist/*.zip` files.

---

## Files to Modify

| File | Action |
|------|--------|
| `stock-sync/stock-sync.php` | Bump `Version` and `STOCK_SYNC_VERSION` to `0.5.0` |
| `stock-sync/assets/css/admin.css` | Full rewrite: centered layout, cards, gradients, pills, progress, toasts, sticky actions |
| `stock-sync/assets/js/admin.js` | Add toast helper, stepper wiring, dismissible hero, skeleton states, replace `alert()` calls |
| `stock-sync/admin/views/page-wrapper.php` | Unified gradient header panel with tabs & distributor selector |
| `stock-sync/admin/views/tab-sync.php` | Hero card, step indicator, upload card, mapping/preview/result cards |
| `stock-sync/admin/views/tab-test.php` | Search card, before/after comparison card |
| `stock-sync/admin/views/tab-log.php` | Log entries inside rounded card |
| `stock-sync/includes/class-admin.php` | Minor: ensure assets load; no functional changes needed if CSS/JS paths stay same |
| `dist/stock-sync-v0.5.zip` | Create new zip; do NOT touch existing `v0.0`–`v0.4` zips |

---

## 1. Version Bump (`stock-sync.php`)

```php
// Before
 * Version: 0.4.0
// After
 * Version: 0.5.0

// Before
define('STOCK_SYNC_VERSION', '0.4.0');
// After
define('STOCK_SYNC_VERSION', '0.5.0');
```

---

## 2. CSS Rewrite (`assets/css/admin.css`)

### Layout
- `.stock-sync-wrap`: `max-width: 800px; margin: 0 auto; padding: 24px 0;`
- Remove default WP `.wrap` left-alignment feel

### Unified Header Panel
- `.stock-header-card`: `border-radius: 16px; background: linear-gradient(135deg, #f0f6fc 0%, #e8e0f7 100%); padding: 24px 28px; margin-bottom: 24px; border: 1px solid rgba(200,200,200,0.3);`
- Title + tagline stacked left
- Distributor selector as clean inline dropdown (no box)
- Tabs as pill-style links: `.stock-header-tabs a` with `border-radius: 20px; padding: 6px 14px;`
- Active tab: solid `--wp-admin-theme-color` background, white text
- Inactive tab: transparent, subtle hover

### Cards
- `.stock-card`: `background: #fff; border-radius: 16px; border: 1px solid #e0e0e0; padding: 24px 28px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);`
- Card headings: `font-size: 18px; font-weight: 600; color: #1d2327; margin-bottom: 8px;`
- Card descriptions: `font-size: 13px; color: #646970; margin-bottom: 20px;`

### Hero Card (Sync Tab)
- `.stock-hero-card`: gradient background `linear-gradient(135deg, #f0f6fc 0%, #ede7f6 100%)`, dismissible with × button
- Content left, optional decorative icon right
- Tagline: "Upload a distributor price list, review mappings, preview changes, and apply availability updates — all in one place."

### Step Indicator
- `.stock-stepper`: horizontal 4-step bar (Upload → Map → Preview → Apply)
- Active step: filled `--wp-admin-theme-color` circle + bold label
- Completed step: checkmark icon in purplish-blue
- Future step: gray empty circle

### Upload Card
- Dropzone: `border-radius: 16px; border: 2px dashed #c3c4c7;` → hover: `border-color: #7c3aed; background: #faf5ff;`
- Button: full width, `border-radius: 12px;`, `--wp-admin-theme-color` bg

### Tables Inside Cards
- `.stock-card-table`: wrap widefat inside card, remove outer borders
- Table header: `background: #f6f7f7; border-radius: 8px 8px 0 0;`
- Row separators: `1px solid #f0f0f0`, no vertical borders
- First/last row rounding via `border-radius` on cells

### Status Pills
- `.stock-pill`: `display:inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;`
- `pill-success`: `#d4edda / #155724`
- `pill-warning`: `#fff3cd / #856404`
- `pill-error`: `#f8d7da / #721c24`
- `pill-info`: `#f0f6fc / #135e96`

### Progress Bar
- Container: `border-radius: 12px; background: #e8e8e8; height: 10px;`
- Fill: `border-radius: 12px; background: linear-gradient(90deg, var(--wp-admin-theme-color), #7c3aed);`

### Sticky Action Bar
- `.stock-sticky-bar`: `position: sticky; bottom: 0; background: #fff; border-top: 1px solid #e0e0e0; padding: 16px 28px; border-radius: 0 0 16px 16px;`
- Only visible inside mapping/preview cards when table is long

### Toast Notifications
- `.stock-toast`: `position: fixed; top: 52px; right: 20px; padding: 12px 18px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 99999;`
- Types: success (green left border), error (red), warning (yellow), info (blue)
- Auto-dismiss after 4s with slide-out animation

### Results Stat Grid
- Replace plain results table with 4 mini stat cards in a grid:
  - `.stock-stat-grid`: `display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;`
  - Each stat: `border-radius: 12px; padding: 16px; text-align: center;`
  - Number: `font-size: 24px; font-weight: 700;`
  - Label: `font-size: 12px; color: #646970;`

### Skeleton Loading
- `.stock-skeleton`: `background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 200% 100%; animation: stock-shimmer 1.5s infinite;`

---

## 3. JS Updates (`assets/js/admin.js`)

### Toast Helper (new)
```js
function showToast(message, type) { ... }
```
- Append fixed-position div to body
- Auto-remove after 4s
- Replace ALL `alert()` calls with appropriate toast type

### Stepper Wiring
- Add `updateStepper(stepIndex)` function
- Called on: upload success → step 1, mapping shown → step 2, preview shown → step 3, results shown → step 4

### Dismissible Hero
- Event listener on `.stock-hero-dismiss`
- Sets `display:none` on hero card
- Optional: store dismissal in `localStorage` so it stays hidden across page loads

### Skeleton States
- Show skeleton rows briefly before populating mapping/preview tables
- Remove skeleton when data arrives

---

## 4. View Files

### `page-wrapper.php`
- Wrap everything in `.stock-sync-wrap`
- Replace `h1` + distributor form + `nav-tab-wrapper` with `.stock-header-card`:
  - Left: title "Stock Sync" + tagline "Automate product availability from distributor price lists"
  - Right: distributor `<select>` inline
  - Below: `.stock-header-tabs` pill links for Sync / Test / Log
- `stock-tab-content` wraps the included tab view

### `tab-sync.php`
- **Hero card** (dismissible): explains the 4-step flow, what the plugin does
- **Step indicator** bar: 4 steps, updated via JS
- **Upload card**:
  - Heading: "Upload Price List"
  - Description: "Drag and drop your .xlsx file or click to browse."
  - Dropzone + advanced options (inside card) + Upload button
- **Mapping card** (hidden initially):
  - Heading: "Review Product Mappings"
  - Sticky action bar at bottom with "Confirm Mappings & Continue"
  - Table inside card with `.stock-card-table`
- **Preview card** (hidden initially):
  - Heading: "Sync Preview"
  - Progress bar inside card
  - Summary text
  - Table inside card
  - Sticky action bar with "Apply Sync"
- **Results card** (hidden initially):
  - Stat grid (4 cards)
  - Details table below

### `tab-test.php`
- **Search card**:
  - Heading: "Test Single Product"
  - Search input + button
  - Results dropdown below
- **Comparison card** (hidden initially):
  - Heading: "Product Details"
  - 3-column table (Field | Current | After Update) inside card
  - Action button in card footer

### `tab-log.php`
- **Log card**:
  - Heading: "Sync Log"
  - If empty: centered friendly message inside card with subtle icon
  - If data: table inside `.stock-card-table`

---

## 5. Build `dist/stock-sync-v0.5.zip`

Command:
```bash
cd /Users/vmosiichuk/git/wp-pricelist-automation && \
zip -r dist/stock-sync-v0.5.zip stock-sync/ -x "stock-sync/.phpunit.cache/*" "stock-sync/.phpunit.result.cache" "stock-sync/vendor/*" "stock-sync/tests/*" "stock-sync/.gitignore" "stock-sync/composer.*"
```

Verify:
```bash
ls -lh dist/stock-sync-v0.5.zip
# Should exist and be ~fresh timestamp
# Existing v0.0–v0.4 zips must remain untouched
```

---

## Verification Checklist

- [ ] Version header reads `0.5.0`
- [ ] Page is centered with max-width ~800px
- [ ] Header panel has gradient background and pill tabs
- [ ] All major sections are wrapped in rounded cards (`border-radius: 16px`)
- [ ] Upload dropzone has gradient border on hover
- [ ] Mapping/preview tables have clean row separators inside cards
- [ ] Status text uses pill badges
- [ ] Results show as 4 stat cards, not a plain table
- [ ] Step indicator updates correctly through the flow
- [ ] Toasts appear instead of `alert()` popups
- [ ] Hero card is dismissible
- [ ] Tests pass: `cd stock-sync && vendor/bin/phpunit`
- [ ] New zip `dist/stock-sync-v0.5.zip` exists
- [ ] Existing zips `v0.0`–`v0.4` are untouched
