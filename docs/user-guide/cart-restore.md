# Cart restore

Sending a customer back to a cart they abandoned.

## Getting a link

On the dashboard's **Highest-Value Abandoned Carts** widget, the **Share** action copies a restore link for that cart.

The link is a front-end URL on the site where the cart was started, of the form:

```text
https://your-site/actions/best-sellers/cart/restore?number=<cart number>
```

The cart number is Commerce's own order number, not the reference. The URL needs no other parameters. The widget offers **Share** only for carts with an email address.

## What happens when the customer opens it

Opening the link makes the cart the visitor's active cart, shows a "Your cart has been restored" notice, and redirects to the page set in Commerce's **Load cart redirect URL** setting.

## Who can restore a cart

| Cart belongs to | Visitor | Result |
| --- | --- | --- |
| No customer | Anyone | The cart is restored. |
| A guest | Logged out | The cart is restored. |
| A registered customer | That customer, logged in | The cart is restored. |
| A registered customer | Logged out | A "Login Required" page, with a login link that returns to the restore URL. |
| A guest or a registered customer | A different logged-in user | The same page, saying the cart belongs to another account. |

Here, a registered customer has an active or pending user account, and a guest has neither, which is how Commerce records checkout by email alone. The [Customers](./customers.md) report counts pending accounts as guests. A link to a guest's cart, or to a cart with no customer, restores for any logged-out visitor, so treat those links as secrets.

## When a link stops working

- The cart has been completed. The link reports that the order is already complete.
- The cart has been purged. Commerce deletes inactive carts on the schedule set by its **Purge inactive carts** settings, After Commerce deletes a cart, the link returns a 404 error. The dashboard widget shows the store's purge duration, or says purging is disabled.
- The cart number is missing or wrong.

## Requirements

The route is a front-end action, so the visitor needs no control panel access. Restoring sets the cart on the visitor's session, so it takes effect in the browser that opened the link.
