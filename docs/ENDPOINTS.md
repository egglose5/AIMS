# Endpoint Reference

This is the current route surface of the Control App and its built-in Identity UI.

## Core operational routes

| Route | Notes |
| --- | --- |
| `/` | Dashboard |
| `/products` | Nebula Product Registry Round 1 page |
| `/initial-inventory` | Inventory/Stash |
| `/initial-inventory/labels` | Inventory labels |
| `/production` | Production/Dynamo |
| `/production/labels` | Production labels |
| `/production/customer-order-label` | Customer order label flow |
| `/fulfillment` | Fulfillment/Dash |
| `/shows` | Shows/Lynks |
| `/vendor-shows` | Vendor-facing show portal |
| `/show-orders` | Show order workflow |
| `/show-inbox` | Brain email intake for shows |
| `/show-brain` | Show Brain reasoning dashboard |
| `/email-hub` | Business email intake |
| `/square-control` | Square catalog/sync tooling |
| `/catalog-audit` | Sellable product audit |
| `/sales` | Square sales dashboard |
| `/online-orders` | Online orders |
| `/stock-and-consumables` | Stock/consumables |
| `/purchase-orders` | Purchase orders |
| `/employees` | Employee dashboard |
| `/settings` | Settings and integration configuration |

## Research and brain routes

| Route | Notes |
| --- | --- |
| `/brain-core` | Brain Core dashboard |
| `/brain-communications` | Brain communications |
| `/scout` | Scout landing page |
| `/scout-discovery` | Scout discovery |
| `/scout-research` | Scout research |

## Identity routes

The app also exposes the built-in ASP.NET Core Identity pages under `/Account/*`, including:

- `/Account/Login`
- `/Account/Register`
- `/Account/ForgotPassword`
- `/Account/ResetPassword`
- `/Account/Manage`

## Notes

- Protected app routes redirect to `/Account/Login` when the user is not authenticated.
- The account POST handlers are mapped in `Components/Account/IdentityComponentsEndpointRouteBuilderExtensions.cs`.
- Docker compose publishes the app at `http://localhost:8080`.
