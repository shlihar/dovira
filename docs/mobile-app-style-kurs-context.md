# Mobile App Style Context From `kurs`

Source project: `/Users/andriishlikhar/Desktop/project/kurs`
Purpose: use this as the mobile UI reference when redesigning `dovira` mobile pages, especially account dashboards, tabbed areas, profile management, reviews, analytics, and billing.

## High-Level Direction

The `kurs` mobile UI feels closer to a native app than a responsive desktop site. It works because the layout is compact, predictable, and built around repeated scan-friendly rows/cards. It avoids oversized dashboard blocks, heavy decorative surfaces, and large empty card areas.

Core traits:

- Light app background: `#f7f8fa`.
- Sticky mobile header, fixed bottom navigation, and safe-area spacing.
- Main content uses `px-4`, `py-4/5`, `gap-2/3/4`.
- Cards are compact white surfaces with subtle borders and very light shadows.
- Text is small but structured: labels `11px`, body `12-13px`, titles `15-22px`.
- Important cards are usually horizontal rows: icon/status at left, content in the middle, action/status at right.
- Mobile hides secondary desktop decoration instead of shrinking everything.
- Motion is functional: page entrance, accordion open, button press, completion/check state, loading state.

## App Shell Pattern

Source examples:

- `src/App.tsx:224-265`
- `src/components/BottomNav.tsx:11-29`
- `src/index.css:18-21`

Pattern:

- Root: full-height app surface, `bg-[#f7f8fa]`.
- Mobile header: `sticky top-0`, white, `border-b border-gray-100`, compact `px-4 py-3`.
- Sidebar becomes off-canvas on mobile with backdrop and `transition-transform`.
- Bottom navigation is fixed, white, border top, safe-area aware.
- Main content receives bottom padding equal to bottom nav height.

Important measurements:

- Header button: `w-9 h-9`, `rounded-xl`.
- Header title: `15px`, bold.
- Bottom nav: `min-h-[56px]`, icons `20px`, labels `9px`.
- Page padding: `px-4 sm:px-6 lg:px-7`, `py-5 lg:py-7`.
- Bottom safe area: `padding-bottom: env(safe-area-inset-bottom, 0px)`.

Apply to `dovira`:

- Keep global mobile header visible.
- Use one consistent fixed bottom nav for account tabs.
- Avoid internal duplicate "app headers" unless the page genuinely needs a subheader.
- Add bottom padding to content so last controls do not hide behind navigation.

## Card Surface Pattern

Source examples:

- Dashboard stat cards: `src/components/Dashboard.tsx:151-183`
- Course header card: `src/components/MyCourse.tsx:83-112`
- Assignment cards: `src/components/Assignments.tsx:75-108`

Base visual language:

```text
bg-white
rounded-2xl
border border-gray-100
shadow-[0_1px_4px_rgba(0,0,0,0.05)]
p-4 or px-3/4 py-3
```

Cards are not large decorative panels. They are compact content containers. If a card has only one metric, it should usually be a horizontal row, not a tall tile.

Good mobile proportions:

- Stat card: `p-4`, `flex items-center gap-3.5`.
- List row: `px-3 sm:px-4 py-3 sm:py-3.5`, `flex items-center gap-3`.
- Accordion header: `px-3/4 py-3`, compact `gap-2.5/3`.
- Card radius: usually `rounded-2xl`, but content inside uses `rounded-xl` or smaller.
- Shadow: extremely light, never deep floating shadows.

Apply to `dovira`:

- Replace big KPI tiles with compact row-like cards.
- Avoid tall cards with values floating in corners.
- Use one clear row structure: icon, text/value, optional status/action.
- Keep cards visually quiet. Use color through icons/statuses, not huge gradients.

## Typography Scale

Source examples:

- Dashboard title and stats: `src/components/Dashboard.tsx:103-183`
- MyCourse card/list: `src/components/MyCourse.tsx:83-190`
- Assignment list/form: `src/components/Assignments.tsx:35-155`

Observed scale:

- Page title: `text-xl` mobile, `lg:text-[22px]`.
- Section heading: `14-15px`, bold.
- Card title: `12-15px`, usually semibold/bold.
- Secondary label: `11px`, `text-gray-400`.
- Body text: `12-13px`, `leading-snug` or `leading-relaxed`.
- Badges/chips: `11px`.
- Bottom nav labels: `9px`.

Rules:

- Use `leading-snug` for compact cards.
- Use `truncate`, `min-w-0`, and `flex-shrink-0` aggressively in rows.
- Hide long helper text on mobile if it makes the row harder to scan.
- Prefer `font-semibold` for labels and `font-bold` only for primary values/titles.

Apply to `dovira`:

- PRO account mobile text is currently too large in many places.
- KPI cards should not use hero-scale numbers unless the card is full-width and central to the screen.
- Button labels should be short on mobile: "Редагувати", "Переглянути", "Далі", "Готово".

## Icon Pattern

Source examples:

- Stat icons: `src/components/Dashboard.tsx:161-174`
- Module icons: `src/components/Dashboard.tsx:199-204`
- Course hero icon: `src/components/MyCourse.tsx:83-88`

Patterns:

- Small row/list icons: `28-38px`, `rounded-lg/xl`.
- Main stat icon: `44px`, circular or rounded, soft background.
- Hero icon: `60px` mobile, `72px` desktop.
- Icon itself is usually `15-22px`.
- Icons use Lucide with `strokeWidth` around `1.8-2.5`.

Important: the icon container size follows the density of the row. A compact KPI row uses a `34-44px` icon, not `58px` if the row becomes cramped.

Apply to `dovira`:

- For compact KPI cards, use `40-44px` icon containers.
- For prominent hero/status cards, use `58-60px`.
- Do not mix giant icon circles with tiny cramped text in a two-column KPI grid.
- Keep icon colors semantic and soft:
  - blue: info/views
  - green: completed/success/clicks
  - amber: warning/rating
  - red/orange: danger/needs attention

## Navigation And Tabs

Source examples:

- Bottom nav: `src/components/BottomNav.tsx:11-29`
- Lesson mobile tabs: `src/components/LessonViewer.tsx:411-430`

Patterns:

- Bottom nav is for primary app sections.
- Page-level mobile tabs are sticky below the header, horizontal, and compact.
- Tab buttons use icon + label, `12px`, `py-3`, bottom border active state.
- Content panels are separated, not all stacked in one long page.

Apply to `dovira`:

- PRO account tabs should behave like bottom app navigation.
- Secondary tabs/filters should be horizontal chips or sticky tab row, not large sidebar buttons.
- Only active tab content should render visibly.
- Switching tabs should feel instant and should not resize the whole shell unpredictably.

## Lists, Accordions, And Dense Workflows

Source examples:

- Course accordion: `src/components/MyCourse.tsx:114-190`
- Assignment accordion: `src/components/Assignments.tsx:65-155`
- Accordion CSS: `src/index.css:87-98`

Patterns:

- Lists are vertical stacks with `gap-2`.
- Each row has fixed compact left affordance, title, small metadata, and right status/action.
- Accordions animate height using grid rows:
  - closed: `grid-template-rows: 0fr`
  - open: `grid-template-rows: 1fr`
  - inner content has `overflow: hidden`
- Rows are active-state friendly: `active:bg-gray-100`, `hover:bg-gray-50`.

Apply to `dovira`:

- Reviews on mobile should become compact rows/cards with one primary action affordance.
- Forms and details should open as accordion/details panels or full-width sections, not large nested cards.
- Avoid putting cards inside cards.

## Motion System

Source examples:

- Global animations: `src/index.css:24-69`
- Page key transition: `src/App.tsx:259`
- Check completion animation: `src/components/Dashboard.tsx:93-101`
- Assignment submission state: `src/components/Assignments.tsx:141-148`

Motion principles:

- Short durations: `0.18s-0.35s` for UI feedback.
- Entrance: `fade-up 0.35s cubic-bezier(0.22,1,0.36,1)`.
- Button press: `active scale(0.96)`.
- Accordion: `0.28s cubic-bezier(0.22,1,0.36,1)`.
- Progress bars/donuts animate once, around `0.8-1s`.
- Loading uses simple pulse/spinner states; no full-page blocking if only one section changes.

Apply to `dovira`:

- Use page/panel `fade-up` when switching tabs.
- Use `btn-press` style on mobile action buttons.
- Use lightweight skeleton/loading only around the block being updated.
- Do not animate layout-heavy properties except controlled accordion grid rows.

## Loading And Error States

Source examples:

- Initial app loading: `src/App.tsx:130-160`
- Error state: `src/App.tsx:164-183`
- Assignment submit state: `src/components/Assignments.tsx:141-148`

Patterns:

- Loading is centered, calm, and branded.
- Error state is minimal with icon, title, helper text, retry button.
- Local submit/loading states are inline: spinner in button or a small success confirmation.

Apply to `dovira`:

- For analytics period changes, use local card skeleton instead of page-level loading.
- For review hide/reply actions, use button-level loading and optimistic disabled state.
- For tab switches, prefer immediate content switch with small entrance animation.

## Mobile Layout Rules To Reuse In `dovira`

Use these as defaults:

- Page wrapper: `background: #f7f8fa`.
- Content padding: `16px` horizontal.
- Vertical stack gap: `8-16px`, usually `gap: 8px` for lists and `gap: 16px` for sections.
- Cards: white, `border: 1px solid #f3f4f6` or similar, subtle `0 1px 4px rgba(0,0,0,.05)`.
- Card radius: `16px` or `20px`; avoid very large pill cards for dense dashboards.
- KPI cards: horizontal row, icon `40-44px`, value `20-24px`, label `11-12px`, helper `10-11px`.
- Wide status/action card: one row with icon, title/meta, right value/action.
- Button height: around `40-44px`, `rounded-xl`, `13px`, semibold.
- Chips: `11px`, `px 10px`, `py 5px`, `rounded-full`.
- Icons: Lucide-style thin strokes; avoid heavy FontAwesome-filled appearance where possible.

## What To Avoid In `dovira`

- Huge mobile cards with one metric and lots of empty space.
- Floating chips in card corners when they compete with the main value.
- One-column stacks for short KPI cards unless each item is a full action row.
- Desktop sidebars compressed into mobile.
- Large text sizes copied from desktop.
- Multiple nested cards.
- Decorative gradients as the primary structure.
- Heavy shadows or thick borders.
- Hiding the global mobile header.

## Suggested Migration Approach For `dovira`

1. Define a mobile app shell first:
   - sticky header
   - fixed bottom nav
   - safe-area padding
   - page content `px-4`, bottom padding for nav

2. Define shared mobile primitives:
   - `.app-card`
   - `.app-row`
   - `.app-icon`
   - `.app-chip`
   - `.app-section-title`
   - `.app-tabbar`
   - `.app-bottom-nav`

3. Rebuild pages by workflow:
   - PRO overview: hero, attention rows, KPI rows, chart, recent reviews.
   - Reviews: compact review list, detail panel, reply/hide actions.
   - Profile: form sections as accordions or short grouped blocks.
   - Analytics: filter chips, metric rows, chart cards.
   - Billing: subscription status row, payment/action rows.

4. Verify each mobile page with screenshot review at:
   - 390x844
   - 430x932
   - 375x667

## Quick Mapping To Current PRO Account Problems

Current issue: mobile PRO overview feels too large and inconvenient.

Use `kurs` approach:

- Hero should be compact, not desktop-like.
- KPI cards should be stat rows, not tall tiles.
- Attention cards should read as actionable rows.
- Reviews should use list-row density.
- Filters should be chips or sticky tab bars.
- Secondary data should be hidden/collapsed until needed.
- Each screen should fit meaningful content in the first viewport.

The target is not "prettier cards"; it is a mobile app workflow where a user can scan, tap, and move through tasks quickly.
