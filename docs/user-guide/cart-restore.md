# Cart restore

Sending a customer back to a cart they abandoned. Audience: store admins and CS leads following up on abandoned carts.

## Getting a link

On the dashboard's **Highest-Value Abandoned Carts** widget, the **Share** action copies a restore link for that cart. Send it to the customer.

The link is a front-end URL of the form:

```text
https://your-site/actions/best-sellers/cart/restore?number=<cart number>
```

The cart number is Commerce's own order number, not the reference. Nothing else is needed on the URL.

## What happens when the customer opens it

The cart becomes their active cart, a "Your cart has been restored" notice is set, and they are redirected to the page configured in Commerce's **Load cart redirect URL** setting.

## Who can restore a cart

| Cart belongs to | Visitor | Result |
| --- | --- | --- |
| A guest | Anyone | The cart is restored. |
| A registered customer | That customer, logged in | The cart is restored. |
| A registered customer | Logged out | A "Login Required" page, with a login link that returns to the restore URL. |
| Anyone | A different logged-in user | The same page, saying the cart belongs to another account. |

A logged-in visitor can never take over another account's cart, and a logged-out visitor can never open a registered customer's cart. Anonymous and guest carts have no such restriction, so treat those links as shareable secrets: anyone holding one can load that cart.

## When a link stops working

- The cart has been completed. The link reports that the order is already complete.
- The cart has been purged. Commerce deletes inactive carts on the schedule set by its **Purge inactive carts** settings, and once a cart is gone the link 404s. The dashboard widget shows the store's current purge duration next to the cart list, or says purging is disabled.
- The cart number is missing or wrong.

## Requirements

The route is a front-end action, so the visitor needs no control panel access. Restoring sets the cart on the visitor's session, so it takes effect in the browser that opened the link.
