# Cinematic site rebuild handoff

## Current GitHub state

- Repository: `frimpomaasync/frimpomaasync-site`
- Working branch: `codex/full-customer-path`
- Latest pushed commit: `c7d2575`
- Live Hostinger site: unchanged
- Important: merging or pushing to `main` triggers the Hostinger deployment workflow. Do not merge until the full browser suite passes and Nana Frimpongmaa approves the final preview.

## What is complete

### Task 1: shared motion foundation

Complete and independently reviewed.

- Cinematic motion tokens and shared components
- Transparent hero overlay navigation with compact white scroll state
- Desktop and mobile header clearance
- AA contrast and 44px header controls
- Reduced-motion handling for cinematic motion, sticky CTA, and chat typing dots
- GitHub browser run: https://github.com/frimpomaasync/frimpomaasync-site/actions/runs/30492918923

### Task 2: homepage cinematic hero

Complete and independently reviewed.

- Full-bleed original `hero-scene-wide.jpg`
- Working leak finder preserved
- Desktop and 390px responsive layouts
- First paper-rise transition
- Contrast, overflow, keyboard, pointer, and reduced-motion coverage
- GitHub browser run: https://github.com/frimpomaasync/frimpomaasync-site/actions/runs/30494184919

### Task 3: SynKasa product story

Complete and independently reviewed.

- Cinematic product-stage hero using the original photo
- Four status rows
- Sticky Answer, Qualify, Book, and Follow up story
- Mobile static fallback
- Calculator, script, chat, Soma, tiers, fit, ownership, guarantee, FAQ, and fit-form journey preserved
- GitHub browser run: https://github.com/frimpomaasync/frimpomaasync-site/actions/runs/30496044227

## What is built but not finished

### Task 4: Siesie five-role story

Production work is built and pushed.

- Optimized `assets/siesie-hero.jpg`, 1536 by 1024, about 528 KB
- Cinematic hero with a readable five-row role panel
- Five roles in the required order:
  1. Scheduling
  2. Money
  3. Coordination
  4. Account management
  5. Reporting
- Five role cards
- Five audit checkboxes
- Desktop sticky role story
- Tablet two-column and mobile one-column layouts

The latest GitHub run failed because of a test API call, before it could evaluate the product behavior:

- Failed run: https://github.com/frimpomaasync/frimpomaasync-site/actions/runs/30497632232
- Job: https://github.com/frimpomaasync/frimpomaasync-site/actions/runs/30497632232/job/90730041093
- Error: `Page.wait_for_function() takes 2 positional arguments but 3 were given`
- Location: `tests/site_qa.py`, around line 621

The Python Playwright `arg` parameter is keyword-only. Change the call from the equivalent of:

```python
page.wait_for_function(expression, index)
```

to:

```python
page.wait_for_function(expression, arg=index)
```

Commit and push that test correction, then wait for the feature-branch GitHub Action. If another real assertion fails, fix the behavior rather than weakening the five-role contract.

## Work not started

Continue the committed plan at:

`docs/superpowers/plans/2026-07-29-cinematic-site-motion.md`

Remaining tasks:

1. Task 5: supporting pages, original photos, and retired-image sweep
2. Task 6: copywriting accuracy and mandatory humanizer pass
3. Task 7: full desktop, tablet, mobile, keyboard, reduced-motion, route, form, and animation testing
4. Task 8: ship check and deployment candidate

The approved design specification is:

`docs/superpowers/specs/2026-07-29-cinematic-site-motion-design.md`

## Verification commands

```bash
node --test tests/journey.test.mjs
python3 -m unittest tests/test_preview_server.py
python3 tests/site_qa.py --static
python3 tests/site_qa.py --foundation
```

The feature-branch workflow installs Chromium and runs the browser suite:

`.github/workflows/test-cinematic.yml`

Local browser launch may fail on this Mac because Chrome aborts inside the Codex sandbox. GitHub Actions is the reliable browser environment used so far.

## Brand and copy requirements

- Always write the full name `Nana Frimpongmaa`.
- No emojis.
- No em dashes in customer-facing copy.
- Do not name the AI vendor in customer-facing copy.
- Use system fonts only.
- Use ink `#101426`, copper-orange `#C2501C`, and white or paper backgrounds.
- Keep the guarantee exactly: `Live in 7 days, or you don't pay.`
- Siesie must show all five roles everywhere the role count appears.
- Run every customer-facing line through the copywriting and humanizer skills before shipping.
- Keep outcomes ahead of deliverables.

## Deployment rule

Do not push or merge to `main` until:

1. Tasks 4 through 8 are complete.
2. The full GitHub browser suite is green.
3. Desktop and mobile screenshots have been reviewed.
4. Nana Frimpongmaa gives final approval.

