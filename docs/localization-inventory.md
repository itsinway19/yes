<!-- DOCUMENTATION-MODERNIZATION-2026-11-15 -->
> **RETAIN_VERIFICATION_EVIDENCE — historical scope only.** This record preserves its original dates, source revisions, results and limits. Instructions, proposals, authorization and sequencing below are historical, not current work orders or design authority. Use the [governing architecture](architecture/BUILDERAD_GOVERNING_ARCHITECTURE.md) and [current implementation](architecture/CURRENT_IMPLEMENTATION.md). Retired-document links point to their exact committed provenance.

# Remaining localization screens

The foundation covers `Auth/Login.vue` and `Internal/Login.vue`. The files below still contain user-facing text that must be translated in later slices. Order follows shared UI dependencies and the navigation exposed from the login pages. This inventory does not authorize translating these screens in Foundation 001.

1. **Shared signed-in navigation:** `Layouts/AuthenticatedLayout.vue` and `Layouts/InternalLayout.vue`. Replace fixed Arabic navigation text and `dir="rtl"` in the internal layout before its child screens are localized.
2. **Advertiser account entry and recovery:** `Pages/Auth/Register.vue`, `ForgotPassword.vue`, `ResetPassword.vue`, `VerifyEmail.vue`, and `ConfirmPassword.vue`. Include their validation, success, and security messages; both login screens link into this flow.
3. **Landing and signed-in home:** `Pages/Welcome.vue` and `Pages/Dashboard.vue`.
4. **Account and device management:** `Pages/Profile/Edit.vue`, `Pages/Profile/Partials/UpdateProfileInformationForm.vue`, `UpdatePasswordForm.vue`, and the shared `Pages/Security/Devices.vue`. Include locale-aware date and number display and Passkey error mapping.
5. **Advertiser workspaces:** `Pages/Workspaces/Index.vue` and `Show.vue`. Include action failures and keep public IDs and credentials directionally isolated.
6. **Internal operations:** `Pages/Internal/Onboarding.vue`, `Administration.vue`, and `Services.vue`. Depend on the localized internal layout and shared security devices screen.

Server-side validation and action messages for each later flow should be translated with the screen that displays them. The existing security notification translations and locale capture remain in place.
