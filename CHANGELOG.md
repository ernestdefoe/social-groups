# Changelog

## 2.5.0

### Fixed — a page of user cards no longer fires a request per card

Every rendered user card fetched its own group badges through
`GET /api/sg-user-groups/{id}`. A page that lists many users — a follower or
following list (ianm/follow-users), a group's member list, the user index, even
several hover cards — therefore fired **one HTTP request per user on the page**,
and every one of those boots Flarum and opens its own database connection.

On shared hosting that caps new database connections per second (Hostinger caps
at 20/s) a list of around 28 users exhausts the pool, and from that point *every*
request to the forum fails at boot with:

```
SQLSTATE[HY000] [2002] Operation not permitted
```

The 500s looked like they came from this extension's endpoint. The endpoint was
the victim of its own fan-out, and the burst took the rest of the forum down
with it. Reported at <https://discuss.flarum.org/d/39840>.

The badge data now rides on the serialized user as `sgGroups`, alongside the
`sgPrimaryGroup` chip that already worked this way, eager-loaded on the User,
Post and Discussion endpoints exactly as Flarum eager-loads `user.groups` for
the same author avatars. A page of user cards now costs **no extra requests at
all**, however many cards it renders.

Measured on Flarum 2.0.0-rc.8 with ianm/follow-users installed, 31 user cards on
one followed-users page:

| | `/sg-user-groups` requests | Total API requests |
| --- | --- | --- |
| 2.4.12 | 34 | 36 |
| 2.5.0 | **0** | 1 |

All 60 badges still render, with no error notices.

### Fixed — the profile no longer reads the same group list twice

Three places want a member's group list: the card badges, the profile's Groups
tab, and the primary-group selector. Two of them render on the same page, so
opening a profile fetched the identical list twice. They now share one cache and
one in-flight request per user, so any number of callers costs one request.

Choosing a primary group now drops that cached list and refreshes the serialized
user, so the new badge appears immediately instead of after a full reload.

### Fixed — a failed badge lookup no longer fills the screen with error notices

Badges are decoration. When the lookup failed, each card raised Flarum's red
"Oops, something went wrong" notice, so a page of user cards filled with them —
which is what made the original fault so alarming to look at. A failure now
degrades quietly to "no badges".

### Unchanged

`GET /api/sg-user-groups/{userId}` behaves exactly as before and still serves
callers that hold only a user id; it is now a fallback rather than the primary
path. No settings, permissions or migrations change, and nothing needs to be
reconfigured after updating.

### A note on table prefixes

This release was also checked against a forum running a custom table prefix
(`fg_`) — the group directory, group pages, analytics, member lists, the profile
tab and posting were all exercised, and all queries resolve correctly. Social
Groups has no table-prefix fault; the errors reported alongside the 500s above
were the connection-pool exhaustion described in the first entry.

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
