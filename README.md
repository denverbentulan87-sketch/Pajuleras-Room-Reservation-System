# Pajuleras Boarding House Management System

A PHP + MySQL website for Pajuleras Boarding House (Poblacion, Inabanga, Bohol), built by Denver Bentulan.

* **Public website** (customers and tenants, no login): see rooms and live bed availability,
  read rates, requirements and house rules, **send an inquiry**, and later **read the landlord's
  reply and send follow-up messages** using an inquiry code.
* **Admin panel** (`/admin`, opened from the **Landlord login** pop-up on the landing page): **one account only** (the landlord). Manages rooms, tenants,
  reservations, room assignment, check-in/check-out, payments and receipts, inquiries, reports.

Customers can never log in or change anything. There is no registration and no second admin
(the database itself refuses a second admin row).

## Requirements
PHP 8.1 or newer (with PDO MySQL, fileinfo, mbstring) and MySQL 5.7+ / MySQL 8 / MariaDB 10.3+.
XAMPP (current version) already includes everything.

## Install with XAMPP (Windows)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy this whole folder to `C:\xampp\htdocs\` and keep the name **pajuleras_bh**.
3. Open **http://localhost/phpmyadmin** > **Import**:
   1. choose `database/01_schema.sql` > **Go** (creates database `pajuleras_bh`, tables, the admin account)
   2. *(optional demo data)* choose `database/02_sample_data.sql` > **Go**
4. Open **http://localhost/Pajuleras Room Reservation System/**
5. Landlord sign in: on the landing page click the **Landlord login** button in the footer (it is also in the mobile menu). It opens a sign-in pop-up.
   There is no separate login page.
   * username `admin`, password `Pajuleras@2026`
   * You are **forced to choose a new password** at first sign-in.

If your MySQL has a root password (or a different port), edit `config/config.php`.

### Without XAMPP (quick test)
```
mysql -u root -p < database/01_schema.sql
mysql -u root -p < database/02_sample_data.sql      # optional
php -S localhost:8080
```
then open http://localhost:8080 (run the command inside this folder).

## First things to do as the landlord
1. Sign in, set your new password.
2. **Settings > Boarding house**: real name, phone, address, GCash number, and the **boarding house photo** shown on the home page (hover zoom and tap-to-enlarge are built in; without a photo a house illustration is shown).
3. **Settings > Rates, requirements & rules**: the text customers see; the advance payment (months).
4. **Rooms**: the demo rooms, rates and tenants are **made-up samples**. Edit or delete them, add
   your real rooms (beds, rent per bed, deposit, who may stay, photo).
   *If you skipped the sample data, the site starts empty: add rooms first.*

## How it works (matches the documentation)
| Step in the process | In the system |
|---|---|
| Customer asks about rooms | **Send an inquiry** (public) > shows an inquiry code |
| Landlord checks availability and replies | **Inquiries** inbox > reply; customer reads it via **Check my inquiry** |
| Collect customer details | **Register tenant** (prefilled from the inquiry) |
| Confirm room / record reservation | **Reservation**: pending > **Confirm** (room is held) |
| Advance payment | **Record payment** > official receipt (printable) |
| Room assignment | Room chosen on the reservation; **Transfer room** later if needed |
| Move in / move out | **Check in** / **Check out** (room status updates automatically) |
| Reports | **Reports**: occupancy, payments, reservations, unpaid balances, tenants (print or CSV) |

**Room status** is automatic: *Available* (open beds), *Reserved* (all beds held by confirmed
reservations), *Occupied* (all beds checked in), *Under maintenance* (set by the landlord).
Shared rooms have several beds, so one room can be partly occupied.

**No double booking.** A reservation holds beds only when *confirmed*. Every confirm, edit,
check-in and transfer re-checks the room's real date ranges and locks the room, so two people
(or two browser tabs) can never take the last bed at the same time. A new tenant may move in
on the day another moves out.

**Billing.** Rent is billed monthly in advance from the check-in date. Balance =
(deposit + rent for each started month) minus (advance + deposit + rent paid). Refunds are limited
to the deposit and rent paid ahead. Payments are never deleted; mistakes are **voided** with a reason.

**Privacy / deleting tenants.** A tenant with no reservations can be deleted. A tenant with history
cannot be deleted (it would break receipts and totals) but can be marked *Inactive*, or have their
**personal details erased** (tenant page > *Privacy*): name, contact, address, ID, guardian, notes
and their inquiry messages are permanently removed; payments stay as "Former tenant T-000X".
If you installed before this feature existed, run `database/03_upgrade_add_erase_option.sql` once.

## Security built in
* Single admin, hashed password (bcrypt), forced change of the default password, session timeout (30 min)
* Login lock after 5 wrong tries; limits on inquiry spam and inquiry-code guessing
* CSRF protection on every form, prepared SQL statements everywhere, all output escaped
* Strict Content-Security-Policy, secure session cookies, upload validation (real image check, random names)
* `includes/`, `config/`, `database/` and `uploads/` are blocked from direct web access (`.htaccess`)

**Before going online:** use HTTPS, set a strong MySQL password (update `config/config.php`), and keep
`'debug' => false`. The site does not send emails (XAMPP has no mail server); the landlord replies
inside the system and can also call or text the number the customer left.

## Folder guide
```
index.php rooms.php room.php inquire.php track.php policies.php   public pages
admin/        landlord panel
includes/     shared code (database, security, availability, billing, layout)
config/       database settings
database/     01_schema.sql, 02_sample_data.sql, 03_upgrade_add_erase_option.sql (old installs only)
assets/       css, js, fonts, images
uploads/      room photos uploaded by the landlord
```

## What is new: pop-up inquiry, GCash payments, chat assistant

* **Send an inquiry** now opens as a pop-up from every button and link (it still works as a normal page without JavaScript).
* **Pay with GCash** (footer, Rates & house rules page, and the chat): a tenant sends money to your GCash number, then reports it
  with their reservation number, contact number and the GCash reference number (a receipt screenshot is optional).
  The report appears in **Admin > GCash payments**. Check it against your GCash history, then **Approve** (it becomes a normal
  payment with an official receipt) or **Reject** with a reason. Screenshots are private and only visible to you.
  * Already installed the system before this update? In phpMyAdmin > Import, run `database/04_upgrade_add_gcash_payments.sql` once.
  * Remember to fill in your **GCash number** in Settings > Boarding house.
* **Chat assistant** (bottom-left of every public page) answers questions about rooms and availability, rates, deposit and advance,
  requirements, house rules, how to pay, location, and the landlord's contact details. It reads your Settings and rooms live, so
  answers stay current. It understands some Cebuano/Tagalog words. It never shares passwords, tenant records or financial reports.
* **Check my inquiry** also opens as a pop-up (look up with your code, read the reply, send follow-up messages). All pop-ups scroll on small screens.
* A **favicon** (house icon) shows in the browser tab. Replace `assets/img/favicon.svg`, the PNG files next to it, and `favicon.ico` if you ever want a different icon.
* The chat assistant also replies politely to "okay", "thank you", praise, and "that's all", including Cebuano/Tagalog thanks ("salamat").

## Latest update: GCash is easy to find, instant inquiry replies, auto-registration, payment reminders

**Upgrading an older install?** In phpMyAdmin > Import, run `database/05_upgrade_add_auto_replies_and_reminders.sql` once.

* **GCash is easy to find.** A blue "Pay by GCash" button sits in the menu, a blue "Pay with GCash" button floats on every page, there is a link under the home page buttons, and a full "Pay by GCash" section shows your number with a Copy button. Tenants can also check a report's status (pending, confirmed with a receipt number, or not confirmed with the reason) using their report code and contact number.
* **Inquiries get an instant, honest reply.** The moment someone sends an inquiry, the system checks the room (open bed for their move-in date, gender policy, under repair) and replies in the pop-up, in their inquiry conversation, and by e-mail if they gave one: **Confirmed** (room has an open bed), **Not confirmed** (room is full, closed, or for the other gender, with rooms that do have space), or a list of open beds when no room was chosen. "Confirmed" never holds a bed. Beds are only held when you confirm the reservation.
* **New people are registered automatically.** From the inquiry (name, gender, contact number, e-mail, school or workplace) a tenant record is created and marked "From inquiry". If the contact number already belongs to a tenant, no duplicate is made. People must tick a consent box first.
* **Rent due dates and payment reminders.** Rent is due one month after move-in, then every month on the same day. Each reservation shows a **Payment schedule** (what is due, when, paid or missing). Tenants are reminded: a few days before the due date, on the due date, and while a payment is missing or only partly paid (repeating every few days, up to a limit). Each reminder is sent only once per due date. Set the timing in **Settings > Reminders & notifications**.
  * **E-mail** and **text message (Semaphore)** are sent automatically once you fill in `config/config.php` (the settings page explains how, with test buttons). The secrets stay in that file, not in the database.
  * **Messenger** (and any channel that is not set up or fails) is prepared in **Admin > Payment reminders** with one-tap buttons. Facebook does not allow automatic Messenger messages without an approved app, so this is always one tap.
  * Tenants are also e-mailed or texted when you approve or reject their GCash report.
  * Every message is logged (who, when, how, result) in **Admin > Payment reminders**.
  * **To run daily even when nobody opens the website:** schedule `php cron/reminders.php` (Windows Task Scheduler) or open `cron_run.php?token=...` (the address is on the settings page) from a cron job. Without that, the site also checks by itself every 6 hours when someone opens the home page or you open the dashboard.
* **Safe, clear and traceable.** Consent before storing details, reports are checked against reservation and contact number, duplicate GCash references are refused, nothing is recorded until you approve it, every payment gets an official receipt, private screenshots are only visible to you, failed attempts are rate-limited, and all actions are in the activity log.
