# v1.0.4 Password Reset & Full Re-test Validation

## Requested change

- Super Admin can reset portal user passwords.
- A new `reset_user_passwords` permission is available in Settings -> Roles & Permission Matrix so Super Admin can grant password-reset authority to any other role when required.

## Security behavior

- Super Admin: reset any user, including self.
- Delegated role: reset only equal/lower-authority users fully inside its own organizational scope.
- No generic `manage_users` password bypass.
- Password confirmation required; minimum 12 characters.
- Current password cannot be reused as the reset value.
- Remember token rotates and existing sessions are revoked.
- Password reset writes an audit event but never the password.

## Re-test result summary

- PHP syntax: PASS (143 files)
- TS/TSX syntax/transpile parse: PASS (36 resource files + Vite config)
- JSON/XML/YAML: PASS
- Docker Compose topology static validation: PASS
- Shell syntax: PASS
- Inertia page mapping: PASS (32)
- Route names: PASS (82 unique)
- Permissions: PASS (43 seeded / 39 referenced by routes/navigation)
- Controller method references: PASS
- Sidebar route mapping: PASS
- Debug/conflict markers: PASS
- Release forbidden-file scan: PASS
- Added PHPUnit regression methods for password reset, scope, session revocation and delegated user management.

## Runtime gate

Composer/Docker are unavailable and `npm install` timed out in the offline build environment. GitHub CI must therefore be green before production acceptance.

## Additional security re-test findings

- **Delegation control:** only Super Admin can grant/remove `reset_user_passwords`; non-Super role managers receive HTTP 403 if they attempt to alter it.
- **Concurrent reset row-lock check:** target user is reloaded with `lockForUpdate()` and reset authority is rechecked inside the transaction before `session_version` is advanced.
- **Session revocation:** password reset rotates `remember_token`, increments `session_version`, records `password_changed_at`, and stale authenticated sessions are rejected by `EnsureActiveUser`.
