# Student online booking — install notes

The booking module lives in `booking/` and reuses the main system's config, login session, styles, logo and fonts.

1. Back up the database and the project.
2. In phpMyAdmin, select your inventory database and run `database/student_booking_schema.sql` once.
3. Make sure students can book: on the Permissions page, turn on **can_reserve** for the Certificate and Diploma tiers, and **can_kit** for any tier whose students may build multi-item kits.

Student portal: `http://localhost/eldoret_campus/booking/reservations.php`. Admin pages for it are in `booking/admin/` and are linked from the main admin sidebar.

## Student flow
- `student/activate.php`: first-time password. A student with a national ID on file in fees can use it. Everyone else gets a code from staff (admin > Student Logins).
- `student/login.php`: admission number + password.
- `reservations.php`: name, course and group come from the fees portal; build a kit, choose pickup time, track bookings.

## Staff flow
- Admin > **Student Logins**: tick "Student ID checked", issue a one-time code (7 days). A new code also resets a forgotten password.
- Admin > Reservations: approve as before; at **issue**, confirm the due date/time (pre-filled from the tier loan period).

## Settings you can change
`studentBookingRules()` at the bottom of `reservation_helpers.php`: pickup hours and days, lead time, how far ahead, max items per kit.
Lockout limits are constants at the top of `auth/student_auth.php`.

## Files
New: auth/student_auth.php, student/{login,activate,logout}.php, student/partials/{header,footer}.php, api/student-items.php, assets/css/student.css, assets/js/student_booking.js, admin/{reservations,student_accounts}.php, reservation_helpers.php, database/student_booking_schema.sql
Thin forwarders to the main system: config/db.php, auth/{session,rbac}.php, admin/partials/{header,footer}.php, fees/partials/student_sync.php
