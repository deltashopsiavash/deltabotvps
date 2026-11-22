# 🖤 جشنواره بلک فرایدی دلتا ۱۴۰۵

## Source snapshot / rollback

The complete GitHub source tree from before the original 24-hour campaign was saved **before any code changes**:

- Backup branch: `backup/pre-black-friday-1405-20261122`
- Backup source: https://github.com/deltashopsiavash/deltabotvps/tree/backup/pre-black-friday-1405-20261122

This is a **source-only** snapshot. It does not back up production database rows, payment history, installed server packages, `.env`, passwords or live Telegram bot state. Create a separate protected SQL backup on the bot server before deploying.

## Setup from Telegram admin panel

1. Update the running bot from `main` using the existing deployment/update procedure (with a DB backup first).
2. Open **مدیریت ربات ⚙️ → 🖤 مدیریت جشنواره بلک فرایدی**.
3. Review default campaign values:
   - Start: **2026-11-23 00:00**, `Asia/Tehran` (جمعه ۶ آذر)
   - End: **2026-11-28 00:00**, `Asia/Tehran`
   - One-month fixed retail plans (28–31 days): **15%**
   - Two-month fixed retail plans (58–62 days): **20%**
   - New customers' first one- or two-month retail purchase: **25%** for **30 customers**
   - Sales prices not compounded with other codes/promotions
   - Unit GB cost for *estimate only*: **1,000 تومان**
   - Eligible plan IDs: **all** 30/60-day fixed retail plans by default
4. Optionally edit percentages, start/end timestamps, eligible plan IDs (`all` or comma-separated numerical IDs), the new-customer capacity and per-GB cost.
5. Press **✅ فعال‌کردن جشنواره** once. Until this action, the campaign remains **OFF** by default, even during the configured times.

The banner appears automatically for customers before/during the enabled event. Customers can buy normally through the usual **خرید اشتراک** and **سرویس‌های من → تمدید** paths. The discounted **final invoice** is stored on the `pays` row; the regular `server_plans` prices never change. No coupon code is needed.

## Eligibility / payment mechanics

- Only standard fixed one-/two-month retail plans and standard renewal plans, including PasarGuard full renewals, are eligible.
- Wallet top-ups, wholesale/agent purchases, custom GB/day purchases, extra volume/day add-ons and existing time-limited special offers are excluded.
- Any pre-existing personal percentage discount and coupon cannot stack with the festival's chosen discount; the festival price uses the ordinary plan list price.
- Each invoice stores an independent campaign snapshot in the SQL table `black_friday_invoices`, including original price, final price, discount, user ID, volume and one-hour payment deadline.
- For newcomer offers, prior purchases in `orders_list` and successful purchase/renewal payment history are checked; mere wallet deposits do not disqualify. A locked, one-hour reservation counts toward the 30-person capacity. Rejected/cancelled/expired unpaid reservations stop consuming capacity.
- Campaign purchases are grouped by scheduled start/end window so subsequent events start with fresh quota and sales reports.
- After the campaign ends, new invoices return to normal prices automatically. An invoice created shortly before midnight keeps its recorded discounted price for its **one-hour payment window**. Receipts submitted in time remain valid for later manual admin review.
- Payment gateways reject **starting** a new checkout after invoice expiry. This doesn't cancel an already-initiated provider transaction that settles later.
- The administrator can press **⛔ توقف اضطراری جشنواره** at any time to stop issuing new discounted invoices.
- The `📊 گزارش فروش و ظرفیت` panel displays completed sales, new-customer redemptions, collected revenue, gross discount and a cost-of-volume estimate. **The estimate is not net profit**.

## Go-live checks

- Do not broadcast the promotion before verifying the bot update and the 15/20/25% invoice prices.
- Test a standard one-month invoice (80,000 → 68,000 تومان) and a two-month invoice (390,000 → 312,000 تومان).
- Test new customers' first one-month invoice (80,000 → 60,000 تومان) and first two-month invoice (390,000 → 292,500 تومان), a repeat customer, two concurrent new-customer requests, and confirm that no coupon can stack.
- Test the receipt process, rotated card number, wallet and gateway payment entry, and admin sales report.
- Check that plan selection list prices may remain unchanged until the final invoice screen; **the invoice amount is authoritative**.
- Verify a few minutes before and after campaign start/end in the `Asia/Tehran` timezone.

## Rollback

The preserved branch includes the complete former GitHub source; restore that revision through your regular deployment method. Switching source alone does not undo database changes or purchases already processed. Keep a DB backup for data-level rollback, and never overwrite a production DB without reconciling live orders.

No deployment to the production server or live transactional testing is performed by the GitHub PR.
## November 23 finance and five-day upgrade

The new campaign schedule is **November 23, 2026 00:00 Iran time** through
**November 28, 2026 00:00 Iran time**, inclusive of Friday, November 27.
The regular percentages stay 15% for one month and 20% for two months.
The first 30 qualifying new customers get 25% on their FIRST eligible
one- or two-month retail purchase, with one-hour temporary capacity reservations.
The VIP percentage does not stack with other discounts.
Existing deployments with exactly the original Nov 27–28 schedule are migrated
on the first configuration read; deliberately customized dates are preserved.

### Finance administration

A new **📊 حسابداری و آمار جامع فروش** button is available to the PRIMARY
owner of the main bot, independent of the seasonal campaign. It includes:
- Day / yesterday / last 7 or 30 days / current Persian month / complete history / custom range.
- Total confirmed service sales, unique buyers, number of invoices,
  average order value, pending/failed, purchase/renewal/add-on breakdown,
  representative plan/customer rankings, and seven-day trend.
- Wallet-funded service orders count as sales; wallet TOP-UPS are shown
  separately, not also counted as service revenue.
- Estimated external cash inflows, one/two-month festival discounts where
  recorded, total volume with coverage/unknown count, estimated volume cost,
  manually entered business expenses and remaining estimated margin.
- Expense categories: server/hosting, fee, support, advertising and other.
  Owner can create expense entries with description and amount, review the
  ten most recent, and confirm deletion. GB unit cost can be edited.

### Financial accuracy limits

This is an **estimated margin**, NOT an audited profit. Historic invoices
record creation time, not always verified settlement time or original payment
method; method splits and cash-flow estimates may therefore be incomplete.
Some volume quantities are missing or unlimited: these are disclosed.
Estimated cost is computed with the **current** default unit cost of
1,000 tomans/GB and will change for past periods if that rate is edited.
Manual business costs count only if entered; refunds and capital spending may
need adjustment. Do not enter estimated per-GB cost twice as a manual expense.

### Safe release checklist

1. Source snapshot before the finance upgrade:
   `backup/pre-finance-dashboard-20261123`. The older pre-Black-Friday
   source snapshot also remains at `backup/pre-black-friday-1405-20261122`.
2. This backup is source-only; back up the LIVE SQL database separately,
   plus the deployment configuration, before installing.
3. The finance upgrade is developed separately from `main` until review.
   Deploy only after reviewing CI checks and confirming the server DB backup.
4. Campaign is initially OFF in the settings. After deployment open
   **مدیریت ربات → مدیریت بلک فرایدی** to verify Nov 23–27,
   15/20/25 percentages and 30 available slots, then explicitly enable.
5. Smoke-test one-month, two-month, new-customer and returning-customer
   invoices, double-discount prevention, receipts/card rotation,
   wallet and payment gateway paths, cash totals, and expense reporting.
6. The PR and GitHub backup DO NOT install or back up the live server.
