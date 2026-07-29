# Cinematic site and motion redesign

Date: 2026-07-29  
Site: frimpomaasync.com  
Status: approved visual direction  
Selected direction: A, Cinematic Working Landscape

## 1. Objective

Give the full customer journey the visual confidence and section movement NaNa Frimpomaa liked on SolidRoad while keeping frimpomaasync.com recognizable, fast, accessible, and accurate.

This is an adaptation, not a copy. The site will use its own photography, offer structure, type, colors, tools, and customer path.

The redesign must:

- Bring the original cinematic pictures back into the live page experience.
- Make the product feel active before the visitor reads every detail.
- Keep the leak finder, calculators, forms, proof, pricing, and qualification path working.
- Show all five Siesie roles accurately.
- Give every page one dominant idea and one obvious next action.
- Keep all public copy focused on the result the buyer gets.
- Respect reduced-motion preferences and work cleanly on a 390px-wide screen.

## 2. Creative principles

### Product clarity

The Steve Jobs angle is reduction. Each screen gets one visual point, one main claim, and one next action. Product behavior is shown wherever possible instead of being explained in a long paragraph.

### Offer clarity

The Alex Hormozi angle is concrete value. Each offer makes the problem, result, speed, price, proof, and risk reversal easy to find. The guarantee remains: "Live in 7 days, or you don't pay." No new earnings, booking, or performance promise will be added.

### Motion with a job

Movement must explain a change of state, direct attention, or connect one section to the next. Decorative movement that competes with the content will be removed.

### Human copy

Existing facts, prices, and commitments stay intact. Copy will be reviewed for clarity, usefulness, and accuracy, then pass through the required two-stage humanizer check before it ships.

## 3. Visual system

The current brand tokens remain:

- Ink: `#101426`
- Copper-orange: `#C2501C`
- White: `#FFFFFF`
- Paper: `#F8F8F9`
- Serif: Iowan Old Style system stack
- Sans and mono: system stacks
- Fonts: local system fonts only

The visual contrast comes from three layers:

1. A cinematic image stage
2. A crisp working product panel
3. A white paper section that rises into the next part of the page

The photography supplies warmth and atmosphere. Ink, copper-orange, and white carry the interface. Copper-orange stays focused on primary actions, active states, paths, and important numbers.

## 4. Photography plan

### Approved assets

`/assets/hero-scene-wide.jpg`

- Used on the homepage and SynKasa.
- Shows the front-desk experience, the glowing inquiry path, and the open-business idea.
- Cropped as atmosphere on small screens so the embedded interface never makes the main copy hard to read.

`/assets/siesie-hero.png`

- Used on Siesie.
- Shows connected work moving across the business.
- Treated as the operating environment, not as the source of the role labels.

### Retired assets

The following images stay out because they contain retired names, promises, prices, phrases, or visual branding:

- `og-client-catcher.png`
- `ai-products-cover.png`
- `og-cover.png`
- `blog/demo-followup-thread.png`

No retired phrase or guarantee will return through an image.

## 5. Sitewide page structure

### Header

The header begins transparent over a cinematic hero on the homepage, SynKasa, and Siesie. It becomes a compact white header after the hero. The change happens once and keeps the current navigation, keyboard support, and booking action.

Interior pages use the white header from the start.

### Cinematic hero

The homepage, SynKasa, and Siesie use the same structural family:

- Full-width image stage
- Left-aligned outcome copy
- One primary action and one secondary action
- One live product or diagnostic panel
- A short trust or product line near the bottom edge
- A white paper section rising over the image

The three pages must feel related without looking duplicated.

### Paper sections

The next white section overlaps the hero with a broad rounded top edge. Its first heading arrives before the supporting content. Sections alternate between:

- Wide editorial statement
- Sticky demonstration with a moving explanation rail
- Practical tool or calculator
- Proof
- Offer and next action

### Supporting pages

Proof, Free, fit forms, confirmation, Blog, Privacy, and Terms use a quieter paper-stage hero. They keep the same type, spacing, controls, and motion language without repeating a large cinematic image on every page.

## 6. Page designs

### 6.1 Homepage

Purpose: help a visitor recognize what is slipping and choose the right next step.

Hero composition:

- `hero-scene-wide.jpg` fills the stage.
- The existing "Stop being the system." message stays the dominant headline unless the final copy check finds a clearer, equally accurate version.
- The 60-second leak finder sits in a floating ink or paper panel on the right.
- A soft dark image scrim protects text contrast.
- The bottom edge carries the simplest service distinction: SynKasa handles the front desk. Siesie handles the work behind it.

The leak finder must be usable immediately. It cannot wait for an entrance animation to finish.

The first white section rises over the image and shows the visitor's diagnosis. The rest of the current journey remains in this order:

1. Diagnosis and same-day action
2. Relevant proof
3. SynKasa, Siesie, or Free path
4. Seven-day guarantee
5. Qualification close

### 6.2 SynKasa

Purpose: let the visitor see the front desk working, understand the cost of missed inquiries, and choose a tier.

Hero composition:

- `hero-scene-wide.jpg` returns with a different crop from the homepage.
- The copy occupies the clean left side.
- The existing working demo becomes the foreground product panel.
- The panel uses a slight perspective at rest and settles square when it becomes active.
- Price anchoring and the seven-day guarantee stay visible without crowding the headline.

The first white section shows the missed-call proof. A later sticky section keeps the product visual in place while the explanation moves through:

1. Answer
2. Qualify
3. Book
4. Follow up

The calculator, response script, Soma section, three tiers, fit guidance, ownership, guarantee, and form path remain intact.

### 6.3 Siesie

Purpose: show an established owner where the back office still depends on them and explain the complete operations build.

Hero composition:

- `siesie-hero.png` fills the stage.
- The headline sits on the left with the `$25,000, once` position.
- A foreground role panel shows all five roles.

The five public role names are exact:

1. Scheduling
2. Money
3. Coordination
4. Account management
5. Reporting

The image may set the scene, but its embedded labels will never stand in for the five-role explanation.

All five roles must appear in the DOM and be visible without a carousel. On desktop, the role panel can reveal one active description at a time while the complete numbered list remains visible. On mobile, the five roles become a normal vertical stack.

The bottleneck check stays before the detailed offer. A sticky workflow section then follows one representative job through the five roles. The application path, fit guidance, ownership, and implementation expectations remain intact.

### 6.4 Proof and Free

Proof uses larger image and video windows with concise explanations. Hover states can reveal the relevant offer, but links and labels remain visible without hover.

Free keeps its problem-based paths. Each path looks like a useful starting point, not a promotional card wall. The first action remains available without animation.

### 6.5 Forms and confirmation

Fit forms use a calm white layout with an ink progress marker, clear labels, visible error text, and one primary action. Motion is limited to validation feedback and confirmation.

The confirmation page makes the review step and booking action clear. It stays noindex.

## 7. Motion system

### Timing

- Button, hover, and input feedback: 140 to 220 milliseconds
- Panel state changes: 220 to 320 milliseconds
- Section entrances: 420 to 560 milliseconds
- Hero image settle: up to 800 milliseconds
- Stagger between related items: 50 to 70 milliseconds

The main ease-out is `cubic-bezier(.22, 1, .36, 1)`. State changes that need balance may use `cubic-bezier(.65, 0, .35, 1)`.

### Hero entrance

The hero loads in this order:

1. Image fades in and settles from a scale near `1.025`.
2. Header and eyebrow appear.
3. Headline lines reveal as one group.
4. Primary action and product panel arrive.
5. The bottom trust line appears.

The complete entrance stays under one second. The page remains usable during it.

### Scroll movement

The white paper section moves upward over the hero as the visitor begins scrolling. Later sticky sections keep one useful visual in place while two to four short explanations advance beside it.

Scroll movement changes only `transform`, `opacity`, or a simple clipping boundary. No layout property is animated. No continuous scroll listener will rewrite the DOM.

When scroll-linked CSS is unsupported, the layout remains complete and uses the existing intersection-based reveal as a fallback.

### Interaction movement

- Buttons lift by no more than 2px on hover-capable devices.
- Cards use border, color, and small translation changes rather than large shadows.
- The active Siesie role uses the copper-orange path and number.
- Calculator and audit results reveal next to the triggering action.
- Copy confirmation changes the button label and exposes a polite live-region message.

There will be no `transition: all`.

### Reduced motion

With `prefers-reduced-motion: reduce`:

- Parallax and image scaling are removed.
- Sticky storytelling becomes a normal document flow where needed.
- Entrances use a short opacity change or appear immediately.
- Automatic movement stops.
- Tools, forms, navigation, and content remain fully available.

## 8. Responsive behavior

### Desktop, 1100px and above

- Two-column cinematic heroes
- Floating product or diagnostic panel
- Sticky visual storytelling
- Wide editorial headings

### Tablet, 700px to 1099px

- Copy and panel remain paired when space allows
- Sticky sections shorten
- Oversized type is capped to preserve line length

### Mobile, below 700px

- Hero copy comes first
- Interactive panel follows in normal flow
- Image becomes an atmospheric background crop
- Five Siesie roles appear as a complete vertical stack
- No interaction depends on hover
- Controls remain at least 44px tall
- No horizontal page overflow at 390px

## 9. Accessibility

- Text over photography must meet AA contrast with a tested scrim.
- Focus rings remain visible on every interactive element.
- Page order stays logical without CSS.
- Links navigate and buttons perform actions.
- Images have accurate alternative text or empty alternative text when decorative.
- Video controls remain keyboard accessible.
- Sticky and animated sections cannot trap scrolling or focus.
- Results and validation messages use suitable live regions.
- Motion never carries information by itself.

## 10. Copy and offer rules

The visual rebuild will not introduce new facts. Prices, tiers, guarantee, product names, booking link, and qualification requirements stay consistent with the approved customer-journey specification.

Each important section answers one buyer question:

- What problem is this fixing?
- What changes for me?
- Can I see it work?
- What does it cost?
- What happens next?

Calls to action name the next result or step. Vague labels such as "Learn more" and "Submit" stay out.

Siesie always says five roles and always shows five roles. SynKasa remains the flagship. "Client Catcher" appears only where a historically necessary "formerly" line is approved.

Public copy contains no vendor names, emojis, em dashes, retired slogans, or ADHD guidance.

## 11. Implementation boundaries

The redesign will extend the current HTML, CSS, and JavaScript rather than replace the working customer journey.

Expected implementation areas:

- `assets/journey.css`: hero, paper-rise, sticky-story, role-panel, responsive, and reduced-motion styles
- `fsnav.js`: header state, reveal orchestration, and small interaction states
- `index.html`: homepage cinematic hero and diagnosis transition
- `synkasa.html`: product-stage hero and sticky front-desk story
- `siesie.html`: cinematic hero, accurate five-role panel, and workflow story
- Supporting pages: compact shared hero and consistent section treatment

Existing calculators, audits, forms, redirects, route handling, structured data, and privacy behavior must keep working.

## 12. Verification and deployment gate

The site cannot be deployed until all checks pass.

Required automated checks:

- Existing JavaScript and preview-server tests
- Every public route returns successfully
- Every internal link resolves
- Leak finder, SynKasa calculator, script copy, Siesie bottleneck check, forms, modal, and navigation work
- Console has no uncaught errors
- Customer-facing files contain no banned vendor names, retired phrases, emojis, em dashes, or ADHD copy

Required browser checks:

- Homepage, SynKasa, Siesie, Proof, Free, both fit forms, and confirmation
- 390px mobile, tablet, and 1280px desktop layouts
- Keyboard navigation and visible focus
- Reduced-motion mode
- No horizontal overflow
- Hero and sticky sections at their start, middle, and end states
- All five Siesie roles visible and readable
- Original pictures load without harming text contrast or page speed

Required review checks:

- Copywriting accuracy pass
- Two-stage humanizer pass on all customer-facing copy and metadata
- UI and responsive review
- Motion review against the approved timing and reduced-motion rules
- Final visual screenshots for NaNa Frimpomaa

Passing tests creates a deployment candidate. Publishing remains a separate final approval because updating the live branch triggers the public site.
