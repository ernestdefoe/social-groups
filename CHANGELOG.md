# Changelog

## 2.5.0

### Fixed — user cards no longer fire one request each

Every rendered user card fired its own `GET /api/sg-user-groups/{id}` to fetch
that member's group badges. A page that lists many users — a follower or
following list (ianm/follow-users), a member list, the user index, even several
hover cards — therefore fired one HTTP request *per user on the page*, each one
booting Flarum and opening its own database connection.

On shared hosting that caps new database connections per second (Hostinger caps
at 20/s) a list of ~28 users exhausts the pool, and from that point *every*
request to the forum fails at boot with

```
SQLSTATE[HY000] [2002] Operation not permitted
```

The 500s appeared to come from this extension's endpoint, but the endpoint was
the victim of its own fan-out, not the cause — the same burst took the rest of
the forum down with it. Reported at
https://discuss.flarum.org/d/39840.

The badge data now rides on the serialized user as `sgGroups`, alongside the
`sgPrimaryGroup` chip that already worked this way, eager-loaded on the User,
Post and Discussion endpoints exactly as core eager-loads `user.groups`. A page
of user cards now costs **zero** extra requests no matter how many cards it
renders.

`GET /api/sg-user-groups/{userId}` is unchanged and still serves callers that
hold only a user id. The component falls back to it when a user was serialized
without our field, and that fallback now:

- de-duplicates and caches per user id, so repeat cards share one request, and
- fails silently — badges are decoration, so a failure must degrade to "no
  badges", never to a screenful of red "Oops, something went wrong" toasts.

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
