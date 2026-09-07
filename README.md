# Coastal AI Summit & SME Trade Fair 2026 — website

The official website for the Coastal AI Summit & SME Trade Fair, hosted by the
**Atusheni Women, Youth & SME Foundation** in collaboration with MICT.

**30 September – 03 October 2026 · Mondesa Multipurpose Centre Hall, Swakopmund, Namibia**

Everything on the public site is edited from a built-in control panel. No code
changes are needed to update content, prices, dates, logos or contact details.

---

## 1. Getting started

### On this computer (XAMPP)

1. Start **Apache** in the XAMPP Control Panel.
2. Open <http://localhost/Atusheni%20Foundations/setup.php>.
3. Choose a username and password. This becomes the administrator account.
4. Delete `setup.php` when you are finished.

The site is then at `index.php` and the control panel at `admin.php`.

### On a live web host

1. Upload every file and folder to the hosting account's public folder
   (usually `public_html`).
2. Make the `data` and `uploads` folders writable (permission `755`, or `775`
   if the host requires it).
3. Open `https://yourdomain.com/setup.php` and complete the short form.
4. **Delete `setup.php` from the server.**
5. Ask the host to switch on the free SSL certificate so the address starts
   with `https://`.

**Requirements:** PHP 7.4 or newer with the `pdo_sqlite` extension (standard on
almost every host). No MySQL database, no configuration file to edit.

---

## 2. The control panel

Sign in at `admin.php`.

| Screen | What it controls |
| --- | --- |
| **Dashboard** | Days to go, new enquiries, quick actions, site health checks |
| **Enquiries** | Every message sent through the website, with CSV download |
| **Bookings & EFT** | Online bookings, proof-of-payment review, approvals — see [BOOKING-PLATFORM.md](BOOKING-PLATFORM.md) |
| **Diary / Payments / Clients / Reports / Email log** | The rest of the online booking system |
| **Services / Opening hours / Blocked dates** | What can be booked online, and when |
| **Pages** | Page titles, hero headlines, menu labels, search descriptions |
| **Content cards** | Hero statistics, Learn/Connect/Grow cards, About numbers, "who you'll meet", masterclass tracks |
| **Programme days** | The day-by-day programme |
| **Sponsorship packages** | Platinum / Gold / Silver tiers, prices and benefits |
| **Stalls, tickets & rates** | The rate table and the drop-down on the enquiry form |
| **Sponsors & partners** | Every logo on the site, grouped by role |
| **Speakers** | Speaker profiles (the section stays hidden until you add one) |
| **Photo gallery** | Home page photos (hidden until you add one) |
| **Questions & answers** | The FAQ on the packages and contact pages |
| **Site settings** | Identity, event dates and countdown, contact details, all home page text, payment details, social links, colours and SEO |
| **Media library** | Upload and manage images and PDFs |
| **Users** | Add colleagues as administrators or editors |
| **Activity** | A log of the last 200 changes |

### Two useful writing tricks

When you type a headline anywhere in the panel:

- `{curly braces}` colour the words inside them —
  `Bring your business {to the coast.}`
- A `|` forces a line break — `Built on the coast.|{Connected to tomorrow.}`

### The live countdown

The clock on every page counts down to the start date in
**Site settings → Event & countdown**. Change the date and the whole site
follows. When the event starts the clock is replaced by your "event is running"
message, and afterwards by your closing message.

### User roles

- **Administrator** — everything, including adding and removing users.
- **Editor** — all content and settings, but cannot manage user accounts.

---

## 3. Where things live

```
index.php  about.php  programme.php  exhibit.php        the public pages
packages.php  partners.php  contact.php
admin.php                                               the whole control panel
setup.php                                               one-time installer (delete after use)
sitemap.php  robots.txt  .htaccess                       search engines & server rules

booking.php   account.php   pay.php                     the online booking system
login.php     register.php  verify-email.php            (see BOOKING-PLATFORM.md)
forgot-password.php  reset-password.php
download.php  verify-ticket.php

inc/    bootstrap.php   database connection, settings, shared helpers
        schema.php      database structure and the starting content
        layout.php      header, navigation, countdown, footer
        enquiry.php     the enquiry form, validation and notification
        admin-lib.php   control-panel engine (auth, fields, uploads, CRUD)

        migrate.php     booking tables and their settings (additive, automatic)
        platform.php    booking core: config, client accounts, statuses, audit
        services.php    services, opening hours, blocked dates, free slots
        eft.php         bookings, payments, proofs, approval, emails
        documents.php   tickets and receipts, via Core/dompdf
        qr.php          QR codes for the tickets
        mailer.php      SMTP, attachments, email log
        client-ui.php   shared pieces for the booking screens
        eft-admin.php   control-panel booking screens
        eft-actions.php what those screens post to

Core/   dompdf          the PDF generator used for tickets and receipts

assets/ site.css  site.js       the public website
        admin.css admin.js      the control panel

images/   original event photos, logos and the registration form PDF
uploads/  everything added through the control panel
data/     the database — never delete this folder
          proofs/ tickets/ receipts/ — private booking files, never served
```

Adding a new editable list to the site means adding one entry to
`admin_resources()` in `inc/admin-lib.php`; the list, form, ordering, delete and
visibility controls are generated from that definition.

---

## 4. Backups

The entire website — all text, prices, partners, enquiries, bookings, payments
and settings — lives in one file inside `data/`. Proofs of payment, tickets and
receipts sit beside it in `data/proofs`, `data/tickets` and `data/receipts`. To
back up, download the whole `data` folder and the `uploads` folder. To restore,
put them back.

Do this before any big change, and after every busy week of registrations.

---

## 5. Security notes

- Passwords are stored hashed; nobody can read them, including you.
- Every form is protected against cross-site request forgery.
- Sign-in attempts and enquiry submissions are rate limited.
- Uploads are checked by content, not by file name — a PHP file renamed to
  `.png` is rejected.
- `data/` and `inc/` are blocked from the web by `.htaccess` and `web.config`,
  and the database file is given an unguessable name at install time.
- After going live, confirm that opening `yourdomain.com/data/` in a browser
  gives an error rather than a file listing. Do the same for
  `yourdomain.com/data/proofs/`, which holds clients' proofs of payment, then
  tick **Private storage confirmed** in Site settings → Bookings & EFT.
- Secrets such as an SMTP password belong in `data/env.php`, which the web
  server never serves. See [BOOKING-PLATFORM.md](BOOKING-PLATFORM.md).

---

## 6. Email

The enquiry form always saves messages to **Enquiries** in the control panel, so
nothing is ever lost. It also tries to email the addresses in
**Site settings → Contact details → Send enquiries to**.

PHP's `mail()` is unavailable on a local XAMPP machine, and some hosts silently
drop it. If email notifications do not arrive, the messages are still in the
panel — check it daily, or ask the host to enable SMTP mail.

Online bookings need email that actually works, because that is how clients
receive their tickets and receipts. Configure SMTP under **Site settings →
Email delivery**; every attempt is recorded in the **Email log** so you can
always see what went out. See [BOOKING-PLATFORM.md](BOOKING-PLATFORM.md).

---

## 7. Real event details already loaded

Registration options, rates and payment details come from the official
*Coastal AI Trade Fair Registration Form 2026*:

- Indoor corporate stall 3×3 — N$ 9,999 · 4×3 — N$ 13,332 · 6×3 — N$ 19,998 · 9×3 — N$ 29,997
- AI Masterclass ticket (SME) — N$ 750, free for sponsored exhibitors and those who booked a stall
- Masterclass & Summit pass — N$ 1,500 per person
- Food / non-food / kiddies stalls and pole tents — N$ 1,000 to N$ 3,000
- Sponsorship: Platinum N$ 50,000 · Gold N$ 25,000 · Silver N$ 12,000
- Payment by direct transfer to the foundation's FNB business cheque account,
  with proof of payment emailed to the event address

Every one of these is editable in the control panel.
