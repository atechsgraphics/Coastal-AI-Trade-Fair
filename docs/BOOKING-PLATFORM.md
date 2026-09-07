# The EFT booking platform

Online bookings paid by electronic funds transfer, added to the existing
Coastal AI Summit website. Clients register, choose a service, date and time,
pay by EFT and upload their proof of payment. A member of staff verifies that
payment, and only then is the booking confirmed and the ticket issued.

Everything already on the site — the pages, the rate table, the enquiry form,
the paper-style registration form at `book.php` — is untouched and keeps
working exactly as before.

---

## 1. The one rule that matters

**Uploading a proof of payment never confirms a booking.**

A booking only reaches *Payment Confirmed* and *Booking Confirmed* when a
signed-in member of staff presses **Approve payment** in the control panel.
That single action is what records the money, produces the ticket and the
receipt, and emails them to the client.

The nine statuses a booking moves through:

| Status | What it means |
| --- | --- |
| Awaiting EFT Payment | Booked and held. Nothing paid yet. |
| Payment Proof Submitted | The client has uploaded a file. |
| Payment Under Review | A member of staff is checking it. |
| Payment Confirmed | The money has been verified in the bank account. |
| Booking Confirmed | Set automatically the moment payment is confirmed. |
| Cancelled | Called off. Any ticket is void. |
| Declined | The proof was not accepted. The client may send another. |
| Completed | The booking has happened. |
| Refunded | The money has been returned. |

---

## 2. Setting it up

### 2.1 The database

Nothing to run. The first time a page loads after these files are in place,
the new tables are created automatically and `storage/schema-version.lock`
records that it is done.

The migration is **additive only**: every statement is `CREATE TABLE IF NOT
EXISTS`, `CREATE INDEX IF NOT EXISTS`, or an `ALTER TABLE` guarded by a column
check. No existing table, column or row is ever changed or dropped. It is safe
to run against a live database and safe to re-run.

To force it to run again (after an upgrade, say), delete
`storage/schema-version.lock` and load any page.

> **Back up first anyway.** Copy the whole `data` folder before upgrading, as
> the main README already advises.

### 2.2 Administrator accounts

Staff accounts are the ones the control panel already uses — no change. Add
colleagues under **Users** in the panel. Both roles can work with bookings:

- **Administrator** — everything, including adding and removing users.
- **Editor** — bookings, payments, approvals, services and settings.

Client accounts are entirely separate (a different table, a different sign-in
page) and can never reach the control panel.

### 2.3 Banking details

**Site settings → Registration & payment.** These are the same fields the site
already had; the booking platform reads them, so they only need entering once.
The account number is the switch: leave it blank and the payment section is
hidden everywhere.

Add a SWIFT/BIC code under **Site settings → Bookings & EFT** if you take
payments from outside Namibia.

### 2.4 Secure storage for uploads

Proofs of payment, tickets and receipts are written to:

```
storage/proofs/     what clients upload
storage/tickets/    generated ticket PDFs
storage/receipts/   generated receipt PDFs
storage/tmp/        the PDF generator's font cache and the scaled logo
```

They live under `storage/`, which the site already blocks from the web, and each
folder gets its own `.htaccess` and `web.config` denying everything. Files are
stored under an unguessable random name — never the name the client uploaded.
They are only ever served through `download.php`, which checks who is asking.

**Check this by hand once, before going live.** Open

```
https://yourdomain.com/storage/proofs/
```

You should get an error page — not a file listing, and not a download. If a
file comes back, ask your host to deny web access to the `storage` folder. Once
you have checked, tick **Private storage confirmed** in *Site settings →
Bookings & EFT*; the dashboard keeps reminding you until you do.

The `data` and `uploads` folders must be writable by the web server
(permission `755`, or `775` if the host requires it).

### 2.5 Email

**Site settings → Email delivery.**

PHP's `mail()` works on some hosts and is silently dropped by many others. It
does not work at all on a local XAMPP machine. Since this is how clients
receive their tickets and receipts, **use SMTP on a live site**:

| Field | Example |
| --- | --- |
| How email is sent | SMTP |
| SMTP host | `smtp.yourprovider.com` |
| SMTP port | `587` |
| SMTP security | STARTTLS |
| SMTP username | `bookings@yourdomain.com` |
| SMTP password | *(see below)* |
| Sender address | `bookings@yourdomain.com` |

The sender address must be one the mail server is allowed to send from, or
messages will be rejected or land in spam.

Every message the site tries to send is recorded under **Email log**, with the
reason when one fails. The dashboard warns you when anything has failed.

### 2.6 Keeping the SMTP password out of the database

Create `storage/env.php`:

```php
<?php
return [
    'BK_SMTP_PASS' => 'your-smtp-password',
    'APP_URL'      => 'https://www.yourdomain.com',
];
```

Any booking setting can be overridden this way: the key is the setting name in
capitals. The file is checked before the settings table and before the real
environment, and it is inside `storage/`, so it is never served to the web. Leave
the password field in the panel blank and it keeps whatever is stored — the
value is never printed back into the page.

### 2.7 What is already set up

The site's own rate list — the thirteen stalls, tickets and tents from the
official 2026 registration form — has already been turned into bookable
services. Names and prices were copied exactly; nothing was invented:

- Stalls, vendor spaces and tents are priced **per booking**.
- The AI Masterclass Ticket and the Masterclass & Summit Pass are priced
  **per person**, and ask for each attendee by name.
- Every service offers **one booking on the opening day** covering the whole
  fair, and the diary is closed either side of the event by two rows in
  **Bookings → Blocked dates**. Delete those two rows if you ever start taking
  bookings outside the event.

**One thing you must review: capacity.** Each service was given a placeholder —
10 for a stall, 100 seats for a ticket — because only you know how many you can
actually sell. Open each service under **Bookings → Services**, set *Capacity
per time slot*, then tick **Capacity reviewed** in *Site settings → Bookings &
EFT*. The dashboard keeps reminding you until you do.

If you add rates later, a **Build services from my rate list** button appears
above the Services list. It only ever creates what is missing, so it is safe to
press twice, and it never touches a service you have edited.

### 2.8 Before you open bookings

1. **Site settings → Bookings & EFT** — set the website address (it is used for
   the links in emails and the QR code on every ticket), and check the payment
   deadline and cancellation rules.
2. **Bookings → Services** — set the real capacity on each one.
3. **Site settings → Email delivery** — configure SMTP.
4. Check the dashboard: it lists everything still missing.

---

## 3. How the money flows

1. **The client books.** They get a reference like `ATF-4K7P2M` immediately,
   and an email with the banking details. Status: *Awaiting EFT Payment*.
2. **They transfer the money**, using the booking reference as the payment
   reference, and upload a PDF, JPG or PNG of the proof. Status: *Payment Proof
   Submitted*. The team is emailed.
3. **Staff open the booking** in the control panel, view the proof, and check
   it against the bank statement. Optionally mark it *Payment Under Review*
   first so colleagues know it is in hand.
4. **Approve** — record the amount actually received, the bank reference and a
   note. The booking becomes *Payment Confirmed*, then *Booking Confirmed*
   automatically; the ticket and receipt are generated and emailed.
   **Or decline** — a reason is required and is emailed to the client, who can
   then upload a corrected proof.

Staff can also attach a proof on the client's behalf, for a confirmation that
arrives by email or over the counter.

---

## 4. Tickets and receipts

Both are produced by the PDF generator that already ships with the site in
`Core/dompdf`. No other PDF library was added.

Each ticket carries the foundation's branding, the client's name, the booking
reference, a unique ticket number, the service, date, time, guest count,
location, booking status, EFT payment status, amount paid, payment reference,
a QR code, a verification code and the issue date.

**Checking tickets at the door:** open `verify-ticket.php`, or scan the QR code
on a ticket, which goes to the same place. Staff can search by verification
code, ticket number or booking reference. A signed-in member of staff also gets
a **Check this guest in** button. The screen says plainly whether a ticket is
valid, already used, for another day, or void because the booking was cancelled
or never paid.

The ticket and receipt can be rebuilt and re-sent at any time from the booking
screen.

---

## 5. What was added

### 5.1 New files

| File | What it does |
| --- | --- |
| `database/migrate.php` | The additive database migration and every booking setting's default |
| `app/platform.php` | Shared core: config, client accounts, statuses, money, audit trail, private storage |
| `app/services.php` | Services, opening hours, blocked dates, slot generation, capacity |
| `app/eft.php` | Bookings, payments, proof uploads, approval, cancellation, emails, exports |
| `app/documents.php` | Tickets and receipts, rendered by `Core/dompdf` |
| `app/qr.php` | A small QR encoder, so tickets work without an internet connection |
| `app/mailer.php` | SMTP client, MIME assembly with attachments, the email template, logging |
| `app/client-ui.php` | Shared pieces for the public booking screens |
| `app/eft-admin.php` | Control-panel screens, settings tabs and readiness checks |
| `app/eft-actions.php` | What the control-panel booking forms post to |
| `booking.php` | The public booking flow |
| `account.php` | The client dashboard |
| `pay.php` | EFT instructions and the proof-of-payment upload |
| `download.php` | The only route by which a stored file leaves the server |
| `verify-ticket.php` | Ticket checking for staff on the door |
| `login.php` `register.php` | Client sign-in and sign-up |
| `404.php` | A branded page for addresses that do not exist |
| `verify-email.php` | Email confirmation |
| `forgot-password.php` `reset-password.php` | Password reset |

### 5.2 Existing files changed

All changes are additive; nothing was removed or rewritten.

| File | Change |
| --- | --- |
| `app/bootstrap.php` | Loads `app/platform.php` and runs the migration when the schema is out of date |
| `app/layout.php` | `site_head()` takes optional title/description/noindex arguments; the menu gains a *Sign in / My bookings* link and an editable button; adds `site_image()`, which serves right-sized copies of photographs |
| `app/admin-lib.php` | Merges in the booking resources, settings tabs and health checks; adds `decimal` and `password` field types and an `after_save` hook |
| `admin.php` | Routes and navigation for the booking screens; the list view gained `list_format` and `filter_options` hooks |
| `assets/site.css` | A `bk-` block appended at the end |
| `index.php` `partners.php` | Images now go through `site_image()` so they are sent at the size they are shown |
| `.htaccess` | An `ErrorDocument` line for the new 404 page |
| `robots.txt` | The private booking pages are excluded from search engines |
| `assets/admin.css` | A block appended at the end |

### 5.3 New pages in the site menu

`booking.php` is registered in the **Pages** list as *Book a service*, so its
title, menu label and hero text are editable like any other page — and it can
be hidden from the menu there. The existing *Book now* entry is untouched; hide
whichever you do not want.

### 5.4 New routes

Public: `/booking.php`, `/account.php`, `/pay.php`, `/download.php`,
`/verify-ticket.php`, `/login.php`, `/register.php`, `/verify-email.php`,
`/forgot-password.php`, `/reset-password.php`

Control panel: `admin.php?p=` `eft_bookings` · `eft_calendar` · `eft_payments`
· `eft_clients` · `eft_reports` · `eft_emails` · `services` · `service_hours`
· `blocked_dates`, and the settings tabs `settings&g=bookings` and
`settings&g=email`.

### 5.5 New database tables

`clients` · `services` · `service_hours` · `blocked_dates` ·
`service_bookings` · `booking_guests` · `eft_payments` · `payment_proofs` ·
`booking_tickets` · `booking_receipts` · `booking_requests` · `email_log` ·
`booking_audit`

One row was added to the existing `pages` table (the booking page). New keys
were added to the existing `settings` table, all prefixed `bk_`. No existing
table was modified.

### 5.6 Settings and environment

Every booking setting is editable in the panel and is prefixed `bk_`. Any of
them can be overridden from `storage/env.php` or the real environment using the
name in capitals — `BK_SMTP_PASS`, `BK_SMTP_USER`, `BK_FROM_EMAIL`, and so on.
`APP_URL` sets the website address used in emails and QR codes.

Banking details reuse the site's existing keys (`bank_account_name`,
`bank_name`, `bank_account_number`, `bank_branch_code`, `bank_account_type`),
so they are only entered once.

---

## 6. Images and page weight

The photographs and logos in this site are full print resolution — the logo
alone is 1.7 MB, and it appears in the header, the footer and the browser tab
of every page. Sent as they are, the home page came to roughly 3.5 MB.

`site_image($path, $width)` in `app/layout.php` fixes that. It makes a copy at
twice the size the image is actually shown, caches it in `uploads/cache`, and
returns that address instead. A photograph stored as a PNG with no transparency
is written out as a JPEG, which is far smaller; logos with an alpha channel stay
PNG. The home page is now about 920 KB in total.

- **Your original files are never touched.** Everything in `images/` is exactly
  as it was.
- The cache rebuilds itself. Delete `uploads/cache` at any time and the next
  page view fills it again.
- A new logo uploaded in the control panel is picked up automatically — the
  cache key includes the file's modification time.
- If GD is unavailable, or an image cannot be read, the original is served and
  the page still works.

Back the `uploads` folder up as the README says, but `uploads/cache` itself is
disposable.

---

## 7. Security

- Client passwords are hashed with PHP's default algorithm and re-hashed when
  the cost changes. Minimum ten characters.
- Email confirmation and password-reset links are single-use, time-limited, and
  only their SHA-256 hash is stored — a leaked database row cannot be turned
  back into a working link.
- Every form is CSRF-protected using the site's existing token.
- Sign-in, registration, password resets, bookings and uploads are all rate
  limited per connection.
- Uploads are checked by upload status, size, extension, real MIME type read
  from the file's own bytes, and a content signature (`%PDF-` for PDFs, a real
  image header for JPG and PNG). A PHP script renamed `.pdf` is refused. Files
  are stored under a random 128-bit name, outside anything the web server
  serves.
- Clients can only ever reach their own bookings, payments, files and profile;
  every screen loads the booking through one ownership check.
- Tickets and receipts can also be reached from the link in a client's own
  email, which carries a per-booking token. Proofs of payment never can.
- Downloads are sent with `nosniff`, a restrictive `Content-Security-Policy`
  and `no-store`.
- Everything that happens to a booking — proof uploaded, payment approved or
  declined, ticket issued, email attempted, status changed, file viewed by
  staff — is written to `booking_audit` and shown on the booking screen.
- Secrets belong in `storage/env.php`, never in a file the web server serves.

---

## 8. Day-to-day

| Screen | What it is for |
| --- | --- |
| **Bookings & EFT** | The queue. Search and filter, then open one to review its payment. The badge counts what is waiting. |
| **Diary** | A month calendar of every booking, colour-coded by status. |
| **Payments** | Every payment record, what the client declared against what was banked. CSV download. |
| **Clients** | Client accounts. Suspend one, mark an address confirmed, or send a password-reset link. |
| **Reports** | Bookings and money over a period, by status, by service and by date. |
| **Email log** | Every message the site tried to send, and why any failed. |
| **Verify a ticket** | The door-check screen. |
| **Services / Opening hours / Blocked dates** | What can be booked, and when. |

Staff can also create a booking by hand (**+ New booking**) for someone who
phoned or walked in; it follows exactly the same EFT workflow.

---

## 9. Troubleshooting

**Clients are not getting emails.** Check **Email log**. If messages are
failing, the reason is recorded there. Almost always this means `mail()` is
being dropped — configure SMTP.

**Nothing appears on the booking page.** A service must be visible *and* have
opening hours. The dashboard says which is missing.

**A slot is not offered.** Check, in order: the day's opening hours, whether
the date is blocked, whether the slot is already full, and the service's
*least notice required* — a slot inside that window is hidden.

**A ticket PDF will not generate.** Check that `Core/dompdf` is present and
that `data/tmp` is writable. The booking is never lost: the ticket can be
rebuilt from the booking screen.

**A client cannot sign in.** Check their account under **Clients** — it may be
suspended, or the email address may still be unconfirmed. You can mark it
confirmed there, or send a reset link.

**A newly added service shows no open dates.** The two rows in **Bookings →
Blocked dates** close the diary either side of the fair, so anything you add is
blocked outside those days too. Delete them to take bookings all year.

**Images look stale after changing one.** Delete the `uploads/cache` folder; it
rebuilds on the next page view.

**The 404 page does not appear.** `ErrorDocument 404 /404.php` in `.htaccess`
assumes the site is at the root of the domain. Running it from a subfolder — as
XAMPP does — needs the folder in front, e.g. `/myfolder/404.php`.
