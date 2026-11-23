# Delta VPN — Black Friday Direct Store (1405)

## Customer journey
On the main Telegram menu the **🖤 Black Friday** button is pinned above other menu buttons when the festival is upcoming or active. It opens a separate storefront: **One month** / **Two months**. Each duration lists ONLY the enabled, explicitly selected, available, paid plans of that duration with its discounted price. No server, category, or renew buttons appear in this storefront. A customer taps a plan and enters the existing single-service checkout; if manual account naming or NPV public-key setup is needed, those necessary fields are still requested by the original provisioning flow.

The storefront shows the standard discount (15% for one month, 20% for two months). The 25% first-purchase promotion for the first 30 eligible new buyers is computed and reserved atomically when the invoice is generated, because capacity may change between menu view and checkout. The discount in the final invoice is authoritative. It cannot stack with coupons, user-level percent discounts, free-volume purchases, agent/group promotions, or limited special offers. No discount is granted to *normal* purchase flow or to any renewal/custom/wallet-topup route.

## Administrator plan selection
Manage Bot → Manage Black Friday → **☑️ Select one-month plans** or **☑️ Select two-month plans**. Each group lists currently active plans (across servers) by volume, title, server, plan ID, and standard price, eight per page. Tapping an item toggles its checkmark (✅ selected, ⬜ not selected). Choices save immediately; the admin can click the same item again to remove the checkmark. Only selected IDs are included in the festival. **No selection means no festival plans or discounts**.

The standard festival settings panel still controls activation, start/end Iran-time schedule, one-month/two-month percentages, first-buy 25% and a 30-buyer VIP quota, and volume unit cost. The campaign's established date and percentage settings are retained during upgrades. Normal plan prices stored in `server_plans` are never changed by the festival.

## Purchase reports
The pre-existing successful-delivery admin report is enriched with a campaign-specific section (only for festival invoices): buyer ID/name/username, selected plan, server, volume and days, VIP vs normal discount, original price, paid price, saved discount, estimated GB cost and pre-overhead estimated margin, and the payment hash. This avoids duplicating the owner's ordinary successful-delivery notification. Delivery problems are not falsely reported as successful sales. The Festival Reports button aggregates completed festival invoices and remaining VIP capacity.

## Deployment safety
- Source backup: `backup/pre-bf-direct-store-20261124`. This is source-only.
- Do not edit production SQL or customer subscriptions manually. Create a separate live DB/runtime backup before running official `update.sh`; that updater removes and reclones the existing app directory.
- Review GitHub CI before merging. The update command remains:
```bash
bash <(curl -s https://raw.githubusercontent.com/deltashopsiavash/deltabotvps/main/update.sh)
```
Choose **1 – Update bot**, then confirm with `y`.
- After installing, first tick the desired plans in each duration. Verify the shop only lists those plans, and test a completed one-month/two-month order, a returning customer, new-customer VIP capacity, coupon exclusion and the owner notification with a low-risk test account.
