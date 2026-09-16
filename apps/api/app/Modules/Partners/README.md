# Partner program implementation

Program versions hold immutable-by-convention snapshots used for attribution and commission. Supported rules: payments (`first`/`recurring`), attribution_window_days (1–365), commission_max_cycles (1–120), exclude_promos (boolean). Unknown rules fail closed. exclude_promos conservatively excludes payments if this workspace redeemed a promotion before that payment was created.

Attribution uses an explicit code before the first paid transaction, within the configured window after workspace creation. Self-referral checks compare acting user, owner and billing owner to partner identity; one IP is not treated as proof. Existing attribution cannot be reassigned. Links have schema foundations; link attribution and partner self-registration/onboarding are outstanding.

Commission accrues only on verified paid amount, with a fixed amount capped to payment or a basis-point rate rounded down. Free access does not accrue commission. Program version, amount and rules are snapshotted. Refund reversals use cumulative integer rounding so partial refunds sum to the exact full commission; ledger is append-only. A refund after payout creates debt.

Payout requests reserve the **full available balance** above the applicable program minimum; partial withdrawals are not supported in this release. A verified payout profile is required. Rows and balance are locked to prevent duplicate reservations. Manual confirmation service requires partners.payout.manage and an audit reason; the administrative caller must enforce its MFA session. It records an already executed payment and does not transfer money. A balance reduced by a later refund blocks automatic confirmation for reconciliation. Payout rejection/release and external transfer adapters remain outstanding.

The partner endpoint exposes only own totals; no GPS, payment customer names or workspace members are returned.
