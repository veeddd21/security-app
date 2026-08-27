Core PHP conversion for Secure360 / Infipre Security.

What is included:
- `public/index.php` front controller
- `app/config.php` app settings
- `app/db.php` mysqli connection helper
- `app/bootstrap.php` session, auth, helpers
- `app/actions.php` form handlers for login and CRUD
- `views/` PHP-rendered UI pages
- `storage/uploads/` file upload destination

Database:
- Import `database/schema.sql` into MySQL/MariaDB.
- Replace the `{PASSWORD_SUPER}`, `{PASSWORD_ADMIN}`, and `{PASSWORD_GUARD}` placeholders in `database/schema.sql` with real hashes from `scripts/seed.php`, or insert your own users manually.

Notes:
- No Composer packages are required for this version.
- Password hashing uses native PHP `password_hash()` and `password_verify()`.
- All data is stored in MySQL via mysqli.

How to get hashes:
- Run `php scripts/seed.php` from inside `php-core/` and paste the output hashes into the SQL file, or use them in your own insert script.

Default demo accounts after seeding:
- Super Admin: richard.infipre@gmail.com / Super@123
- Admin: admin@infipre.local / Admin@123
- Guard: guard@infipre.local / Guard@123
