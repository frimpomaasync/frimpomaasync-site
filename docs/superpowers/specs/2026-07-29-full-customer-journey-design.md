# Full customer journey restructure

Date: 2026-07-29  
Site: frimpomaasync.com  
Status: approved architecture and value layer

## 1. Objective

Restructure the customer-facing site so a visitor can move from a real business problem to a useful next action, relevant proof, the right offer, qualification, a consultation, and payment.

The site must:

- Keep SynKasa as the flagship and Siesie above the ladder.
- Preserve the current visual system, pricing, guarantee, product names, and public URLs.
- Give visitors something useful before asking them to book or apply.
- Use only verified proof and visitor-provided numbers.
- Keep all ADHD guidance out of the website. That guidance applies only to how Codex replies to NaNa Frimpomaa.

## 2. Success criteria

The restructure is successful when:

1. A cold visitor can identify the right path within 60 seconds.
2. Every primary page gives one useful diagnosis, calculation, script, workflow, or next action.
3. SynKasa and Siesie lead to separate qualification paths.
4. Every major call to action advances the visitor toward proof, qualification, consultation, or payment.
5. The existing routes, guarantees, pricing, legal commitments, and brand rules remain consistent.

No conversion-rate claim will be made until real baseline and post-launch data exist.

## 3. Information architecture

```text
Homepage (/)
├── SynKasa (/synkasa)
│   ├── SynKasa fit form (/synkasa-fit)
│   └── Soma demo (/synkasa#soma)
├── Siesie (/siesie)
│   └── Siesie application (/siesie-application)
├── Proof (/portfolio)
├── Free (/free)
│   ├── Blueprint (/planner)
│   ├── Som (/som)
│   └── Live SynKasa demo (shared chat)
├── Blog (/blog/)
└── Qualification confirmation (/fit-thanks)
```

Existing legacy and legal pages remain in place. No live URL is removed. Any retired page continues to use its existing redirect.

## 4. Navigation

The shared header keeps four primary links:

1. SynKasa
2. Siesie
3. Proof
4. Free

The right-side `Book a call` action remains for high-intent visitors. Page-level calls to action do the qualification work:

- Homepage: `Find where work slips`
- SynKasa: `Check if SynKasa fits`
- Siesie: `Run the owner bottleneck check`
- Proof: offer-specific links on each build
- Free: problem-specific starting links

The footer keeps SynKasa, Siesie, Proof, Free, Som, Blog, booking, Privacy, and Terms.

## 5. Journey map

```text
Content, search, referral, or direct visit
                  ↓
        Relevant page or homepage
                  ↓
     Useful diagnosis or immediate fix
                  ↓
             Relevant proof
                  ↓
      SynKasa fit or Siesie application
                  ↓
              Consultation
                  ↓
          Proposal and agreement
                  ↓
               Payment
                  ↓
              Paid intake
```

The public website covers discovery through qualification. The existing sales process covers consultation through payment. The existing paid intake form remains post-payment.

## 6. Page designs

### 6.1 Homepage

Purpose: diagnose the visitor's main leak and route them to one relevant path.

Section order:

1. Outcome-led hero with `Find where work slips` as the primary action and `See it answer` as the secondary action.
2. Local 60-second leak finder.
3. The visitor's result with one same-day fix.
4. Relevant proof preview.
5. SynKasa as the main offer, Siesie as the established-business path, and Free as the trust path.
6. Seven-day guarantee.
7. Consultation close.

Leak finder choices:

- Missed inquiries
- Slow follow-up
- No-shows or unconfirmed bookings
- Back-office work still depends on the owner

Each result must contain:

- What is likely breaking
- One action the visitor can take today
- One link to the most relevant proof
- One link to SynKasa, Siesie, or Free

The leak finder runs entirely in the browser and sends no data.

### 6.2 SynKasa

Purpose: prove the front desk works, help the visitor understand the cost of missed inquiries, explain the three tiers, and qualify the lead.

Section order:

1. Existing hero and live demo.
2. Existing missed-call proof.
3. Missed-inquiry calculator.
4. Existing five-minute response script with copy action.
5. The SynKasa workflow from inquiry through booking and follow-up.
6. Tier comparison: Start, Grow, and Full.
7. Fit and non-fit guidance.
8. Ownership and seven-day guarantee.
9. Frequently asked questions.
10. `Check if SynKasa fits` close.

Calculator inputs:

- Average inquiries per week
- Approximate percentage currently missed or answered late
- Average completed job value
- Approximate percentage of handled inquiries that become jobs

Calculator outputs:

- Estimated inquiries currently at risk per month
- Estimated job value at risk per month
- The exact formula used
- A plain note that this is a scenario based only on the visitor's inputs, not a promise

All calculation happens locally. No input is stored or transmitted.

The tier prices and care prices remain unchanged. Grow remains the recommended tier. The founding rate remains limited to the first three owners and requires a named testimonial.

### 6.3 SynKasa fit form

Purpose: collect only enough information to prepare a useful 15-minute fit call.

Required fields:

- Name
- Email
- Business name
- Business type
- How customers contact the business
- Main inquiry or booking problem
- Approximate weekly inquiry volume
- Current booking method
- Desired result

Optional field:

- Website or social page

On successful submission, send the visitor to `/fit-thanks` with a SynKasa source marker. The confirmation page explains what NaNa Frimpomaa will review and offers the confirmed booking link.

### 6.4 Siesie

Purpose: help an established business see which back-office roles still depend on the owner and determine whether a full operations diagnostic is appropriate.

Section order:

1. Existing outcome-led hero.
2. Five-role owner bottleneck check.
3. Personalized first process to document.
4. The five roles: scheduling, money, coordination, account management, and reporting.
5. A representative work flow from job received through reporting.
6. Fit guidance and the `$25,000, once` position.
7. Ownership and implementation expectations.
8. `Start the Siesie application` close.

The five-role check asks one yes-or-no question per role. Results:

- 0 to 1 owner-dependent roles: give the first manual improvement and route to Free or SynKasa.
- 2 to 3 owner-dependent roles: give the first process to document and invite the visitor to review Siesie.
- 4 to 5 owner-dependent roles: recommend the detailed Siesie application.

The check runs locally and sends no data.

### 6.5 Siesie application

Purpose: collect the operational context needed to decide whether a diagnostic call is worthwhile.

Required fields:

- Name
- Email
- Business name
- Business type
- Team size
- Approximate monthly job or client volume
- Current scheduling process
- Current invoicing process
- Tasks that still require the owner
- Main operational bottleneck
- Intended implementation timing
- Investment readiness

Optional fields:

- Website
- Current tools
- Additional context

On successful submission, send the visitor to `/fit-thanks` with a Siesie source marker. The confirmation page explains the next review step and offers the confirmed booking link.

### 6.6 Proof

Purpose: turn each real build into useful evidence and a reusable workflow.

Each build card must contain:

- The problem
- What the working build does
- A short `Steal this flow` sequence
- The relevant offer or free tool

No result, customer name, number, or testimonial may be added until it is real and approved.

SynKasa demonstrations link to SynKasa or its fit form. Siesie-relevant operations builds link to Siesie. Som links to Som. Other builds keep a clear role label and do not compete with SynKasa.

### 6.7 Free

Purpose: help a visitor solve one small problem now and understand when a complete build becomes useful.

Replace the flat tool wall with problem-based paths:

- `I miss inquiries` → response script, cadence, and live demo
- `I do not know what to automate first` → Blueprint
- `Ideas disappear before I act` → Som
- `I want to see the front desk work` → live demo
- `I need a human diagnosis` → free 15-minute call

The existing script, Blueprint, Som, live demo, and call remain available. No existing free offer is removed.

## 7. Actionable-value standard

Every major page must give the visitor:

1. One concrete diagnosis or observation
2. One action they can use today
3. One transparent explanation of how the answer was produced
4. One relevant next step

Actionable modules appear before the final sales close. They must work without creating an account. Local diagnostic inputs are not transmitted.

## 8. Calls to action

The site uses a consistent hierarchy:

- Primary page action: use the page's diagnostic, proof, or fit step
- Secondary action: see the relevant live build
- High-intent action: book the confirmed 15-minute call

Buttons must describe what happens next. Avoid `Learn more`, `Submit`, and other vague labels.

## 9. Data and privacy

The leak finder, calculator, and bottleneck check use browser memory only while the page is open. They do not use analytics, cookies, local storage, or remote requests.

Qualification forms submit through the site's existing form delivery endpoint. Every submission includes a source value of `synkasa-fit` or `siesie-application`.

The privacy page must be updated to state that qualification forms may collect business type, inquiry or job volume, current process, desired result, timing, and investment readiness when the visitor chooses to submit them.

No sensitive account access, customer records, passwords, payment details, or private business files are requested on public forms.

## 10. Error handling

Interactive diagnostics:

- Prevent negative or impossible numbers.
- Explain missing fields inline.
- Preserve entered values after a validation error.
- Show the formula and assumptions beside every calculated result.
- Provide a useful manual next step when JavaScript is unavailable.

Qualification forms:

- Validate required fields in the browser.
- Disable the submit button only while sending.
- Restore the button if delivery fails.
- Show a plain failure message with `hello@frimpomaasync.com` and the booking link.
- Never show a blank page or raw provider error.

Copy actions:

- Confirm visually when the script is copied.
- Keep the script selectable when clipboard access fails.

## 11. Visual and accessibility requirements

Preserve the current design:

- Ink `#101426`
- Copper-orange `#C2501C`
- White `#FFFFFF`
- Neutral panel `#F8F8F9`
- Iowan Old Style system serif stack
- System sans and mono stacks
- No remote font requests

New modules must:

- Work at 390px and 1280px without horizontal overflow.
- Use visible keyboard focus states.
- Use real labels for every form field.
- Use buttons for actions and links for navigation.
- Respect reduced-motion preferences.
- Meet AA color contrast.
- Avoid shadows or decorative effects that weaken the current design language.

## 12. SEO and internal linking

- Preserve all canonical URLs.
- Add `/synkasa-fit`, `/siesie-application`, and `/fit-thanks` to the site implementation.
- Keep `/fit-thanks` noindex.
- Add the two qualification pages to the sitemap.
- Update page descriptions and structured data only where the new page role changes the public promise.
- Link every proof card to its relevant product or free tool.
- Add contextual product links to relevant blog answer pages without changing their core articles.
- Preserve all existing redirects.

## 13. Testing

Automated checks:

- HTML pages load without console errors.
- All internal links resolve.
- All required form labels and accessible names exist.
- Diagnostics return the expected result for minimum, typical, and maximum test inputs.
- Negative and empty values show inline errors.
- Form failure states expose email and booking fallbacks.
- No banned vendor names, retired phrases, emojis, em dashes, or ADHD content appear in customer-facing files.

Rendered checks:

- Desktop at 1280px.
- Mobile at 390px.
- Full-page screenshots for Home, SynKasa, Siesie, Proof, Free, and both form pages.
- Geometry checks for visible controls, result panels, videos, and sticky navigation.
- Keyboard navigation through every interactive element.
- Reduced-motion check.

Deployment checks:

- Build and test the exact committed source.
- Push only after explicit live-deployment approval because a push to `main` publishes to frimpomaasync.com.
- Render the deployed pages and verify visible text, interactions, forms, nav, footer, and chat.

## 14. Files expected to change

- `index.html`
- `synkasa.html`
- `siesie.html`
- `portfolio.html`
- `free.html`
- `fsnav.js`
- `privacy.html`
- `sitemap.xml`
- `llms.txt`
- Relevant blog answer pages for contextual links

Files expected to be added:

- `synkasa-fit.html`
- `siesie-application.html`
- `fit-thanks.html`

## 15. Explicit exclusions

- No ADHD dashboard, ADHD language, executive operating system, or response-format guidance on the website.
- No pricing change.
- No new product or renamed offer.
- No fabricated customer proof.
- No new analytics, tracking cookies, advertising pixels, remote fonts, or customer accounts.
- No self-serve payment links until NaNa Frimpomaa supplies confirmed replacement links.
- No deletion of existing public pages or free tools.
