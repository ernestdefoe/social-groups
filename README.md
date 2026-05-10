# Social Groups for Flarum 2

A modern social groups extension for Flarum 2. Members can create communities, invite others to join, upload group images and banners, and display a group badge alongside their posts in discussions.

---

## Features

- **Group directory** at `/groups` — searchable card grid with banner images and member counts
- **Group detail pages** at `/groups/{slug}` — full-width hero banner, group avatar, member sidebar
- **Create & edit groups** — name, description, accent color, privacy toggle, group avatar, and banner image
- **Join / leave** — one-click membership; creators cannot leave their own group
- **Image uploads** — group avatar and banner stored directly on your server, no third-party service needed
- **Group badge on posts** — members can select a primary group; a colored badge appears below their username on every discussion post
- **Permission-controlled creation** — admins decide who can create groups (members, mods, or admins only)
- **Groups navigation link** — added automatically to the primary forum navigation bar

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ≥ 8.1 |
| Flarum | ^2.0 |
| PHP extension | `fileinfo`, `curl` |

---

## Installation

```bash
composer require ernestdefoe/social-groups
php flarum migrate
php flarum cache:clear
```

Then go to **Admin → Extensions** and enable **Social Groups**.

---

## Configuration

### Permissions

Go to **Admin → Permissions** and find the **Social Groups** section:

| Permission | Controls |
|---|---|
| **Create social groups** | Which user groups can create new social groups |

Set this to `Members` to let anyone create groups, or restrict it to `Moderators` / `Admins` as needed.

### Image uploads

Images are stored in your forum's `public/assets/social-groups/` directory and served directly. No external storage or API key is required. Supported formats: JPEG, PNG, GIF, WebP. Maximum file size: 5 MB.

---

## How It Works

### Groups directory (`/groups`)

All public groups are listed in a responsive card grid. Each card shows the group banner, avatar, name, member count, and a short description excerpt. A search bar filters groups client-side by name or description.

Users with the create permission see a **Create Group** button in the top-right corner.

### Group detail page (`/groups/{slug}`)

The group page shows:
- A full-width **banner image** (or a color gradient if none is set)
- The **group avatar** overlapping the bottom of the banner
- Group **name**, member count, privacy status
- **Join / Leave** button (hidden from the creator — they cannot leave)
- **Edit** button (visible to the creator and admins)
- A **description** panel
- A **Members** sidebar with avatar chips for up to 24 members

### Group badge on posts

Members can choose one social group as their **primary group** from their account settings. A small colored badge (matching the group's accent color) then appears below their username on every post they make in discussions — similar to how Flarum displays Admin or Moderator labels.

### Creating a group

The create modal collects:
- **Name** — generates a unique URL slug automatically
- **Description** — plain text, up to 2 000 characters
- **Accent color** — six preset swatches; used for the card gradient and member badge
- **Private** toggle — private groups are visible only to members
- **Group image** — square avatar shown on the card and detail page
- **Banner image** — wide image shown as the hero at the top of the group page

The creator is automatically added as the group's first member with the `creator` role and cannot be removed via the leave button.

---

## Upgrading

```bash
composer update ernestdefoe/social-groups
php flarum migrate
php flarum cache:clear
```

---

## Roadmap

- [ ] Group discussion feed — post and reply within a group
- [ ] Invite-only / approval-required membership
- [ ] Group moderators (promote members to admin role)
- [ ] Group search via API (server-side, for large forums)
- [ ] Notification when someone joins your group

---

## License

MIT © Ernestdefoe
