# HE Sign-off — WooCommerce Conflict Doctor

## Purpose

This plugin disables other people's plugins. Before any Automattic HE
recommends it in a real support ticket, a team lead or support operations
owner must explicitly approve it for that use. Without this paper trail,
one incident where a merchant's site breaks during a test creates support
liability and gets the tool blacklisted.

Record the sign-off here. Screenshots or email/Slack summaries are fine —
the artifact matters more than the format.

## What is being approved

- **Plugin name:** WooCommerce Conflict Doctor
- **Version at approval:** _(fill in: e.g. 0.1.0)_
- **Distribution channel:** Private `.zip` shared by HEs during support
  tickets. NOT on WP.org. NOT on the WooCommerce Marketplace.
- **Target merchant profile:** Novice WooCommerce merchant on a host that
  allows writes to `wp-content/mu-plugins/`. Managed hosts (WP Engine,
  Kinsta, Pressable, Flywheel) show a refusal message and are not
  supported in this phase.
- **Blast-radius boundary:** Per-user testing mode only. Live visitor
  traffic is never affected. No "all users" fallback in v1.

## Safety invariants the approver is relying on

1. Every AJAX endpoint requires nonce + `manage_options`.
2. Session options are stored with `autoload = false` and a 1-hour TTL.
3. WooCommerce, the plugin itself, and common security plugins are
   allowlisted and never disabled.
4. On session expiry, MU-plugin deactivation, or merchant abort, the
   full plugin list is restored immediately.
5. Uninstall removes the MU-plugin and all `wcd_*` options.
6. Managed-host detection refuses to install the MU-plugin rather than
   falling back to an all-users mode.

See the design doc and code for full detail:
- Design: [floofy-greeting-flask.md](../../.claude/plans/floofy-greeting-flask.md)
- Session CRUD tests: [tests/unit/TroubleshootModeTest.php](../tests/unit/TroubleshootModeTest.php)
- E2E coverage: [tests/e2e/](../tests/e2e/)

## Approval

| Field | Value |
|-------|-------|
| Approver name | _(fill in)_ |
| Role / team | _(fill in — must be HE team lead or support operations)_ |
| Approval date | _(YYYY-MM-DD)_ |
| Scope of approval | HE-recommended beta only. Revisit before WP.org listing. |
| Conditions / caveats | _(fill in — e.g. "only for novice merchants on non-managed hosts")_ |
| Artifact | _(paste screenshot path, email subject, or Slack permalink)_ |

### How to collect the sign-off

1. Send a link to this file + the design doc to the HE team lead.
2. Ask explicitly: "Can I recommend this plugin to merchants in Zendesk
   tickets for conflict testing? Any conditions I should state in-ticket?"
3. When they say yes, capture the message verbatim (screenshot or paste)
   and fill the table above.
4. Commit the update. No approval, no recommendation.

## Review triggers

Re-run this approval gate before:
- First WP.org release.
- Any change to the allowlist defaults in [`includes/wcd-constants.php`](../includes/wcd-constants.php).
- Any change to the managed-host refusal behavior.
- An incident where a merchant reports a broken site after running the test.
