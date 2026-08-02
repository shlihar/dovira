# DOVIRA Platform Functional Blueprint

## Product scope
- Universal review platform for companies, services, specialists.
- Public trust signals: verified profile, PRO status, moderation transparency.
- Public catalog + profile pages + business cabinet + admin moderation.

## Core entities
- `users` (roles: user, business_owner, moderator, admin)
- `profiles` (public pages)
- `categories` + `profile_category`
- `profile_reviews`
- `review_reports`
- `official_replies`
- `profile_claims`
- `pro_subscriptions`
- `audit_logs`

## Review lifecycle
1. user submits review
2. status=`pending`
3. moderation checks policy
4. status=`published` / `rejected`
5. claimed owner can post `official_reply`
6. reports trigger re-check with `review_reports`

## Access and roles
- guest: read catalog/profile
- user: create review/report
- business_owner: manage claimed profile, post replies
- moderator: review queue and disputes
- admin: full access, categories, plans, audit

## Next implementation phases
1. Migrate catalog/profile pages from static config to `profiles` table.
2. Build review submission endpoint on `profile_reviews`.
3. Add moderation queue in admin panel.
4. Add claim flow (request -> review -> approved owner).
5. Add PRO subscriptions and billing provider integration.
6. Add analytics widgets in business dashboard.

## Safety checklist before production
- rate limit review/report endpoints
- anti-spam checks and captcha
- enforce email verification for review posting
- add audit logs for moderation actions
- setup backups and DB monitoring
