# Changelog

## 2.0.0

### Breaking — schema change

The member's "primary group" pointer no longer lives on the core `users` table.
It moved from the `users.sg_primary_group_id` column to a dedicated companion
table, `social_group_user_primary` (keyed 1:1 by `user_id`), per the
no-migrations-on-core-tables convention (CLAUDE.md §45). Adding/altering columns
on a large `users` table can hold a metadata lock long enough to cause visible
downtime, and core-table columns collide between extensions.

Migrations run in order on upgrade and require no manual steps:

1. `000025` creates `social_group_user_primary` (FK `user_id` → `users`
   cascade-on-delete, FK `group_id` → `social_groups` null-on-delete).
2. `000026` backfills existing non-null `users.sg_primary_group_id` values into
   the new table before the column is removed.
3. `000027` drops `users.sg_primary_group_id`.

Any third-party code reading `users.sg_primary_group_id` directly must switch to
the `User::socialGroupPrimary` relation (`$user->socialGroupPrimary?->group_id`).
The HTTP API surface (`POST /api/sg-primary-group`, `GET /api/sg-user-groups/{userId}`)
is unchanged.
