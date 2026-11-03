# Optional Web App analytics

Analytics in the SvelteKit Web App is disabled unless `GTM_CONTAINER_ID` or
`GA4_MEASUREMENT_ID` is set on the `frontend` service. Compose passes both
optional values to the frontend at runtime; an empty value loads neither the
consent interface nor Google code. An invalid identifier still enables the
consent interface, but is rejected before any Google script URL is built.

The consent interface offers equally prominent accept, reject, and configure
actions. Statistics are off by default. The current choice is stored in
`localStorage` under `webguard_cookie_consent_v1` for up to 180 days and can be
changed or withdrawn from the persistent Cookie settings control. If the
browser cannot persist the choice, analytics remain disabled. This preference
is separate from Laravel authentication and locale cookies.

No Google request is made before opt-in. After opt-in, Consent Mode v2 starts
with all four storage and advertising signals denied; only `analytics_storage`
is granted. Rejecting or withdrawing updates those signals to denied. Public
`/status/*` pages never emit analytics events. The app does not send raw paths,
query strings, page titles, monitoring or status-page IDs, hosts, user IDs,
search terms, account data, email addresses, or error content. The direct GA4
path uses only the fixed `page_path`, `page_location`, and `page_title` values
derived from an allowlisted page category.

## Configuration

Set one or both variables in the deployment environment and redeploy the
frontend service:

```env
GTM_CONTAINER_ID=GTM-ABC1234
GA4_MEASUREMENT_ID=G-ABC1234567
```

GTM takes precedence when both are valid. Configure its container to respond
only to the `webguard_page_view` event and map `page_category` to the GA4 page
path. Do not enable automatic page views, URL/title variables, advertising,
remarketing, user properties, or other tags. Without GTM, the direct GA4 path
sends one page view per SvelteKit navigation, using a fixed category URL.
Allowed categories are `dashboard`, `monitorings`, `incidents`, `teams`,
`maintenance`, `notifications`, `profile`, `admin`, and `authentication`.
Unmatched routes are not tracked.

## Release gates and rollback

This implementation is a technical opt-in mechanism, not a legal approval.
Keep both deployment variables empty until the responsible privacy reviewer
has documented the need for third-party tracking in authenticated areas and
approved the vendor setup, transfer mechanism, data-processing terms, retention,
and user-facing privacy/cookie notice. Confirm that the effective production
CSP permits only the reviewed Google endpoints. No advertising or marketing
tags are approved by this change.

Before activation, verify accept, reject, configure, reload, withdrawal,
language switching, and SPA navigation in a browser with network recording.
Confirm there are no Google requests before consent and on public status pages,
and that the GTM container contains only reviewed tags. If the review or
configuration fails, remove both environment values and redeploy; the banner
and tracking code will no longer be loaded.
