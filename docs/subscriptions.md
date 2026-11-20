# Trials, gift cards and refunds

## Trials

A free trial product that the extension manages from start to finish:

| Happens | The extension |
|---|---|
| The customer creates their Jellyfin user | The trial starts (the time is fetched from Wizarr) |
| During the trial | The client area shows "5 days left – ends …" and "Continue with a subscription"; the box at the bottom of the page shows the time left and "See subscriptions" (can be closed) |
| The trial ends | The user is disabled (not deleted), the trial is closed, the "Trial" role is removed |
| The customer buys a subscription, before or after the end | **The same user** is re-enabled and moved over: history and "continue watching" are kept. No new invitation |
| Not bought 30 days after the end | The user is deleted |

- The box at the bottom of the page says "Your trial has ended – See
  subscriptions" for 14 days after the end, if the customer hasn't bought.
- **One trial per server:** checkout refuses a trial if the customer has had a
  trial or a subscription on the same server before (whatever its status). A
  customer can try each server once, e.g. both Silo and Jellyfin. Together with "one
  Discord account per customer" this makes it awkward to take several trials.
- On Plex a disabled user can't be turned back on; if the customer buys, they
  get a new invitation instead.

**Setup:**

1. Create a product, e.g. "Try for free", with a **Free** plan and your Wizarr
   server.
2. Under the product's Wizarr fields: **Trial: days** = number of days (e.g. 7).
   Leave **Access expires after** empty; it is ignored on trials anyway.
3. Choose **Discord role while active** = "Trial" if you use roles, and
   preferably tick **Require Discord membership**.
4. Set **Per user limit** = 1 on the product (extra safety).
5. The paid product needs nothing new: the takeover happens by itself.

## Gift cards

A product with **Gift card: months** (e.g. 1 or 3) on a Paywarr server is a gift
card. It is bought like any product (a one-time plan is natural) and gives a
one-time **code** instead of access:

- The code is worth what the buyer paid, a part per billing period, for as many
  periods as the months: on a monthly plan that many months are free; on a
  yearly plan the same amount is taken off. It works once, for a year, on your
  Paywarr subscriptions (not trials, top-ups or other gift cards).
- The buyer gets the code by mail, and sees it on their subscription with
  whether it is used. **Send it to a friend** sends it by mail, with the
  buyer's name and an optional greeting.
- The friend chooses a subscription and enters the code under "Coupon" in the
  cart.
- Refunded or cancelled before it is used, the code stops working.

The codes are Paymenter coupons, so you also see them under Admin → Coupons.

## Refunds

When you refund an invoice **in full** with the **Refund** button on the
invoice in Paymenter, the subscription it paid for is suspended, just as if
it were overdue: the user is disabled and keeps their history. The customer
gets no "suspended" email, because Paymenter's template for it talks about
unpaid invoices.

- **Partial refunds change nothing**, for example a discount, or one of two
  payments when someone was charged twice.
- **Upgrade invoices** (e.g. adding Seerr later) are left alone.
- To undo it, press **Unsuspend** on the service in admin.
- Paymenter cancels the subscription later in the usual way, a number of days
  after its due date (**Admin → Settings → Cronjob**).
- To turn it off: **When an invoice is refunded in full** → **Do nothing** on the
  Wizarr server.

**Not covered:** refunds you make directly at PayPal or Stripe, and
chargebacks. Paymenter never hears about those, so neither does Paywarr. Press
**Suspend** on the service yourself.

## Deleting a customer

Paymenter's **Delete** on a user deletes the subscriptions directly in the
database without telling the extension. So the extension listens for the
deletion and removes the customer's access first: the Jellyfin/Wizarr user,
unused invitations, the Seerr account and Discord roles.

If the access can't be removed (e.g. because Wizarr is down), **the customer
isn't deleted**, and admin shows "The user was not deleted" with the reason. Try again when Wizarr answers. That way there is
never a Jellyfin user left behind without a customer in Paymenter.

[← Paywarr](../README.md)
