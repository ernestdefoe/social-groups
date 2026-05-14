# Social Groups for Flarum 2

A modern social groups extension for Flarum 2. Members can create their own communities, post discussions and polls, react to posts, share content across groups, and manage membership — all without requiring Flarum tags.

**Current version: 2.3.0**

---

## Features

### Group Management
- **Group directory** at `/groups` — responsive card grid with banner images, member counts, and server-side search
- **Group detail pages** at `/groups/{slug}` — full-width hero banner, group avatar, member list, about panel
- **Create & edit groups** — name, description, accent color, privacy toggle, membership type, group avatar, and banner image
- **Quick-action menu** — a vertical ⋮ menu on each group card lets authorized users edit or delete a group without navigating to the group page first
- **Image uploads** — group avatar and banner stored directly on your server; no third-party service needed (JPEG, PNG, GIF, WebP · max 5 MB)
- **Permission-controlled creation** — admins decide which user groups can create social groups
- **Featured groups** — admins can pin groups to a dedicated "Featured" row at the top of the directory; featured groups are sorted first in the API
- **Groups navigation link** — added automatically to the primary forum navigation bar
- **Group RSS feeds** — each public group exposes a valid RSS 2.0 feed at `/groups/{slug}/feed.rss`; a link icon appears in the group hero for easy subscription

### Membership
- **Open or approval-required** — groups can be set to open (anyone joins instantly) or require the creator to approve each request
- **Join / leave** — one-click for open groups; "Request to Join" and "Pending…" states for approval groups
- **Invite members** — group creators and moderators can invite any forum user directly by username, bypassing the group's join flow
- **Join requests panel** — creators and admins see a panel listing pending requests with Approve / Reject buttons
- **Remove & ban members** — creators and moderators can permanently remove a member; removed members cannot rejoin
- **Promote / demote** — creators can promote any member to moderator or demote them back to member from the members sidebar
- **Member badges on profiles** — each user's Flarum profile card shows a row of chips for every group they belong to, linking directly to those groups

### Group Discussions & Feed
- **In-group discussion feed** — fully independent from Flarum's tags system; posts stay inside the group
- **Thread view** at `/groups/{slug}/d/{discussionId}` — full post list, inline reply composer, nested replies
- **Rich text formatting** — post content is processed through Flarum's formatter, so BBCode and Markdown work out of the box
- **File & image attachments** — attach images, videos, PDFs, and other files via the paperclip button; handled by [fof/upload](https://github.com/FriendsOfFlarum/upload)
- **Emoji reactions** — 6-emoji reaction picker (👍 ❤️ 😂 😮 😢 😡) on any post; counts shown inline; one reaction per user, toggleable
- **Nested replies** — reply to a specific post within a thread; replies are visually indented under their parent; nesting is limited to one level
- **Pinned posts** — creators and moderators can pin any discussion to the top of the group feed; a thumbtack badge marks pinned posts
- **Post search** — a debounced search bar above the feed filters discussions by title or first-post content in real time
- **Post sharing** — share any discussion into another group you belong to; a group picker modal lets you select the destination and add an optional comment; shared posts render a quoted card showing the original group, author, and content snippet
- **Edit & delete** — authors can edit or delete their own posts; group moderators can delete any post or discussion
- **Paginated feed** — 20 discussions per page with Previous / Next navigation
- **Link previews** — when you type or paste a URL in the composer, an Open Graph preview card is automatically fetched and attached to the post

### Polls
- **Attach a poll to any post** — click the poll icon in the composer toolbar to add a poll with a question and 2–6 options
- **Single or multi-select** — toggle "Allow multiple choices" to let members vote for more than one option
- **Live results** — vote bars update instantly with percentage fill and vote counts; your vote is highlighted; optimistic updates with server-side revert on failure
- **Closed polls** — optionally set an end date; after expiry the poll shows results only (voting disabled)
- **Zero N+1 queries** — poll data (options, vote counts, actor votes) is batch-loaded alongside discussions

### Media Gallery
- **"Media" tab** on each group page — a second tab alongside the main discussion feed
- **Image grid** — all images attached via fof/upload or embedded as `<img>` tags are shown in an auto-fill responsive thumbnail grid
- **Lightbox** — click any thumbnail to open a full-size overlay with previous/next navigation (keyboard arrows supported), author info, and a "View post" link
- **Pagination** — 24 images per page

### Analytics
- **Group analytics panel** — collapsible panel in the group sidebar for creators and moderators
- **Member growth** — bar chart of new members per day for the last 30 days
- **Post volume** — bar chart of posts per week for the last 8 weeks
- **Top reacted posts** — ranked list of the 5 most-reacted posts, each linking to its discussion
- **Summary stats** — total members, total posts, and total reactions at a glance

### Notifications
- **New post** — group members who have participated in a discussion receive an in-app alert when a new top-level reply is added
- **New reply** — the author of a parent post receives an alert when someone replies directly to their post

### Security
- Post content is run through Flarum's formatter on save and only sanitized HTML is served to clients — raw user input is never rendered directly
- All endpoints verify group membership before allowing reads of private groups or writes of any kind
- Banned members are blocked from rejoining via the join endpoint

### Theme Compatibility
- All colors use CSS custom properties (`var(--primary-color)`, `var(--body-bg)`, `var(--control-bg)`, `var(--muted-color)`, etc.) so the extension adapts to any Flarum 2 theme, including **Avocado**
- Responsive layout works on mobile, tablet, and desktop

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ≥ 8.3 |
| Flarum | ^2.0 |
| [fof/upload](https://github.com/FriendsOfFlarum/upload) | ^2.0 |
| PHP extensions | `fileinfo`, `curl` |

> **fof/upload** is a required dependency and will be installed automatically by Composer. After installation, go to **Admin → Extensions → FoF Upload** and configure your storage adapter and allowed file types.

---

## Installation

```bash
composer require ernestdefoe/social-groups
php flarum migrate
php flarum cache:clear
```

Then go to **Admin → Extensions** and enable **Social Groups** and **FoF Upload**.

---

## Configuration

### Permissions

Go to **Admin → Permissions** and find the **Social Groups** section:

| Permission | Controls |
|---|---|
| **Create social groups** | Which user groups can create new social groups |
| **Edit & delete any social group** | Which user groups can edit or delete any group, not just their own |

**Create social groups** — set this to `Members` to let anyone create groups, or restrict it to `Moderators` / `Admins`.

**Edit & delete any social group** — grant this to your `Moderators` group to allow them to edit group details or delete groups they did not create. Site admins always have this ability.

> Group creators can always edit and delete their own group — this permission only extends that ability to other trusted users.

### Membership types

When creating or editing a group, choose:

| Type | Behaviour |
|---|---|
| **Open** | Any logged-in member can join instantly |
| **Approval required** | A join request is queued; the creator or a moderator must approve it |

> Creators and moderators can always **invite** a user directly regardless of the membership type.

### Featured groups

Only site admins see the "Feature group / Unfeature group" option in the group card's ⋮ menu. Featured groups appear in a highlighted row at the top of the directory and are sorted first in API responses.

### Group RSS feeds

Each public group's feed is available at `/groups/{slug}/feed.rss`. Private groups return a `403` error. The feed includes the 20 most recent discussions with their first-post content and links.

### Media gallery

The gallery automatically discovers images from posts containing `[upl-file]` BBCode (fof/upload) or inline `<img>` tags. No configuration is required.

### Analytics

The analytics panel is visible only to the group's **creator**, **moderators**, and site **admins**. It loads lazily the first time the panel is expanded to avoid unnecessary queries on page load.

### Group avatar & banner images

Images are stored in `public/assets/social-groups/` and served directly. Supported formats: JPEG, PNG, GIF, WebP. Maximum size: 5 MB.

### Post attachments (fof/upload)

1. Go to **Admin → Extensions → FoF Upload**
2. Choose a storage adapter (local disk, S3, etc.)
3. Set allowed MIME types and maximum file size
4. Enable **Convert images to WebP** if your server has GD or Imagick with WebP support

---

## How It Works

### Groups directory (`/groups`)

All public (and member-visible private) groups are displayed in a responsive card grid. Each card shows the banner, avatar, name, member count, privacy/approval tags, and description excerpt. Featured groups are shown in a separate highlighted section above the main grid.

### Group detail page (`/groups/{slug}`)

The page is divided into:

- **Hero** — full-width banner, group avatar, name, member count, privacy & approval indicators, RSS link (public groups), and action buttons
- **Tab bar** — switches between the **Posts** feed and the **Media** gallery
- **Main column** — discussion feed with search bar, composer (with poll and attachment support), and paginated discussion cards
- **Sidebar** (right column):
  - *Join Requests panel* — visible to creators/moderators on approval-required groups
  - *About this Group* — description, privacy tag, approval tag
  - *Members* — list of members with role badges; moderators see Remove/Promote/Demote buttons and an **Invite** button
  - *Analytics panel* — collapsible; visible to creators, moderators, and admins

### Polls

When composing a post, click the **poll icon** in the toolbar to attach a poll. Add a question, 2–6 options, and optionally enable multiple-choice voting. The poll is submitted alongside the post. Results are shown inline in the feed card as animated percentage bars. Clicking an option votes immediately (optimistic update); clicking again removes the vote.

### Post sharing

Click the **⋯ menu** on any discussion card and choose **Share post**. A modal lets you pick any other group you belong to and add an optional comment. The shared post appears in the target group's feed with a quoted card showing the original group name, author, and content snippet.

### Member badges on profiles

When viewing any user's profile card anywhere on the forum (e.g., hovering their username in a Flarum discussion), a "Groups" row appears at the bottom of the card showing pill-shaped badges for each group they belong to. Clicking a badge navigates to that group. Private groups are hidden from viewers who are not members of them.

---

## Upgrading

```bash
composer update ernestdefoe/social-groups
php flarum migrate
php flarum cache:clear
```

---

## Changelog

### 2.3.0

- **Polls** — attach single or multi-select polls to group posts; live vote bars with optimistic updates
- **Member badges on profiles** — group membership chips appear on Flarum user cards forum-wide
- **Post sharing** — share any discussion into another group with a group picker modal and quoted card display
- **Media gallery** — "Media" tab on every group page with lightbox, keyboard navigation, and pagination
- **Group analytics** — collapsible analytics panel for moderators: member growth, post volume, and top reacted posts
- **Remove & ban members** — creators and moderators can permanently remove members from the group
- **Featured groups** — admin-only ability to pin groups to a featured row in the directory
- **Group RSS feeds** — RSS 2.0 feed at `/groups/{slug}/feed.rss` for every public group
- **Pinned posts** — moderators can pin discussions to the top of the feed
- **Post search** — debounced search bar in the group feed filters by title and content
- **Emoji reactions** — 6-emoji reaction picker on every post with live counts
- **Nested replies** — reply to a specific post within a thread
- **Notifications** — in-app alerts for new posts and direct replies
- **Link previews** — automatic Open Graph preview cards in the post composer

---

## License

MIT © Ernestdefoe
