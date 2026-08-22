# Endpoint Reference

This app is a Blazor Web App deployed via the app Dockerfile. The routes below are the ones exposed by the application and its built-in Identity UI.

## App Pages

| Route | Kind | Notes |
| --- | --- | --- |
| `/` | Razor page | Home dashboard |
| `/sales` | Razor page | Square Sales dashboard |
| `/employees` | Razor page | Employee dashboard |
| `/online-orders` | Razor page | Online Orders dashboard |
| `/stock-and-consumables` | Razor page | Stock and Consumables dashboard |
| `/purchase-orders` | Razor page | Purchase Orders dashboard |
| `/settings` | Razor page | Settings page |
| `/auth` | Razor page | Auth-required test page |
| `/Error` | Razor page | Error page |
| `/not-found` | Razor page | Not found page |

## Account Pages

| Route | Kind | Notes |
| --- | --- | --- |
| `/Account/Login` | Razor page | Sign in |
| `/Account/Register` | Razor page | Register a user |
| `/Account/ExternalLogin` | Razor page | External login callback page |
| `/Account/AccessDenied` | Razor page | Access denied page |
| `/Account/ConfirmEmail` | Razor page | Email confirmation |
| `/Account/ConfirmEmailChange` | Razor page | Email change confirmation |
| `/Account/ForgotPassword` | Razor page | Password reset request |
| `/Account/ForgotPasswordConfirmation` | Razor page | Password reset request confirmation |
| `/Account/ResetPassword` | Razor page | Reset password form |
| `/Account/ResetPasswordConfirmation` | Razor page | Reset password confirmation |
| `/Account/ResendEmailConfirmation` | Razor page | Resend confirmation email |
| `/Account/InvalidPasswordReset` | Razor page | Invalid reset token page |
| `/Account/InvalidUser` | Razor page | Invalid user page |
| `/Account/Lockout` | Razor page | Lockout page |
| `/Account/LoginWith2fa` | Razor page | Two-factor login |
| `/Account/LoginWithRecoveryCode` | Razor page | Recovery-code login |
| `/Account/RegisterConfirmation` | Razor page | Registration confirmation |

## Account Management Pages

| Route | Kind | Notes |
| --- | --- | --- |
| `/Account/Manage` | Razor page | Account overview |
| `/Account/Manage/ChangePassword` | Razor page | Change password |
| `/Account/Manage/DeletePersonalData` | Razor page | Delete account data |
| `/Account/Manage/Email` | Razor page | Update email |
| `/Account/Manage/EnableAuthenticator` | Razor page | Enable authenticator app |
| `/Account/Manage/ExternalLogins` | Razor page | Connected external logins |
| `/Account/Manage/GenerateRecoveryCodes` | Razor page | Generate recovery codes |
| `/Account/Manage/Passkeys` | Razor page | Passkey management |
| `/Account/Manage/PersonalData` | Razor page | Download personal data |
| `/Account/Manage/ResetAuthenticator` | Razor page | Reset authenticator app |
| `/Account/Manage/SetPassword` | Razor page | Set password |
| `/Account/Manage/TwoFactorAuthentication` | Razor page | Two-factor settings |
| `/Account/Manage/RenamePasskey/{Id}` | Razor page | Rename a specific passkey |

## Identity POST Endpoints

These are mapped in `Components/Account/IdentityComponentsEndpointRouteBuilderExtensions.cs` and are used by the Identity Razor components.

| Route | Method | Notes |
| --- | --- | --- |
| `/Account/PerformExternalLogin` | POST | Starts an external login challenge |
| `/Account/Logout` | POST | Signs the current user out |
| `/Account/PasskeyCreationOptions` | POST | Returns passkey creation options as JSON |
| `/Account/PasskeyRequestOptions` | POST | Returns passkey request options as JSON |
| `/Account/Manage/LinkExternalLogin` | POST | Links an external login provider |
| `/Account/Manage/DownloadPersonalData` | POST | Downloads personal data as JSON |

## Local Development URLs

The app runs on the standard launch profile URLs:

| URL | Purpose |
| --- | --- |
| `https://localhost:7251` | Main app |
| `http://localhost:5299` | HTTP redirect endpoint |

## Notes

- The root page and the dashboard pages are Blazor routes, not separate API controllers.
- The account POST routes expect antiforgery tokens when they are invoked from forms.
- The account management routes are only fully useful when the user is authenticated.
