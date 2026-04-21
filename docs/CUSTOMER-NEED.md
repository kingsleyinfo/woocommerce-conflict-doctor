# CUSTOMER-NEED: Automated plugin/theme conflict testing for novice WooCommerce merchants

**Draft for Linear.** Paste the body below into a new Linear issue under the
appropriate Support / Developer Experience project. Attach a link to the
design doc and this repo.

---

## Problem

Novice WooCommerce merchants who hit a plugin/theme conflict (broken
checkout, admin crashes, missing emails) currently have two options:

1. Follow [How to test for conflicts](https://woocommerce.com/document/how-to-test-for-conflicts/)
   and manually deactivate plugins one by one. They get stuck, break
   their site further, or abandon.
2. Escalate to HE support. An HE walks them through the workflow over
   Zendesk. Typical cost: **1-2 hours of combined HE + merchant time
   per ticket.**

Because the cost is so high, current Automattic policy is to recommend
conflict testing **only as a last resort**, and to refer novice merchants
who can't self-serve to a **paid service**. That's the strongest
possible demand signal: we've already decided human time is too
expensive for this workflow and pushed it to paid.

## Evidence (as of 2026-04-20)

- Live Zendesk case on 2026-04-20: merchant was a novice, HE (me) had to
  take over and run the conflict test directly. Exactly the scenario
  this plugin targets.
- Organizational policy signal: HEs are advised to escalate to a paid
  service rather than walk a novice through manual testing.
- Existing WooCommerce docs page for manual testing signals recurring
  demand — WooCommerce invested in content but not tooling.
- No first-class automated tool exists anywhere in the ecosystem.

## Proposed solution

A merchant-installable WordPress plugin — **WooCommerce Conflict
Doctor** — that automates the conflict-test workflow behind a single
"Find what's broken" button. Per-user testing mode (cookie-scoped
MU-plugin filter on `option_active_plugins`) so live visitor traffic is
never affected. Binary search across active plugins with a human-in-loop
"did that fix it?" at each round. Under 5 minutes of merchant time from
click to culprit.

Design approved 2026-04-20 via `/autoplan`. Implementation through step
6 (E2E coverage) landed on `main`; HE sign-off gate is the remaining
blocker before beta distribution.

## Distribution plan

- **Phase 1 (dogfood):** HE-only staging sites. Internal safety
  validation.
- **Phase 2 (HE-recommended beta):** Private `.zip` shared by HEs
  during real support tickets. Gated on HE team lead sign-off — see
  `docs/he-approval.md`.
- **Phase 3 (public):** WP.org listing after ~100 real uses with zero
  safety incidents. WooCommerce Marketplace listing after that.

## Success metrics

- Median merchant time-to-culprit < 5 minutes (vs. 1-2 hours today).
- ≥90% accuracy on staged conflict cases.
- Zero incidents of sites left in a broken state after 100+ beta uses.
- ≥5 HEs recommend it in active tickets within 4 weeks of Phase 2.
- ≥70% "this was helpful" on the one-click post-wizard survey.

## Ask

- **Sponsor:** An HE team lead or support operations owner to approve
  Phase 2 distribution and file the sign-off (see `docs/he-approval.md`).
- **Visibility:** Put this in front of the WooCommerce Support team so
  it's on the radar when we move to public release.
- **Future:** A decision-maker for the WP.org listing and the
  WooCommerce Marketplace pitch once Phase 2 metrics are in.

## Links

- Design doc: `~/.claude/plans/floofy-greeting-flask.md`
- Repo: `kay-automattic/woocommerce-conflict-doctor` (private)
- HE approval gate: `docs/he-approval.md`
