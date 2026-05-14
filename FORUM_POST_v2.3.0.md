# Social Groups 2.3.0 — Polls, Member Badges, Post Sharing, Media Gallery & More

It's been a busy development cycle for **Social Groups**, and version 2.3.0 is the biggest update yet. This release adds 14 new features across group discovery, content creation, moderation, and analytics. Here's everything that's new.

---

## 🗳️ Polls

Members can now attach a poll to any group post directly from the composer. Click the **poll icon** in the toolbar, type a question, add 2–6 options, and optionally enable **multiple-choice voting**. Results appear inline in the feed as animated percentage bars — your vote is highlighted, and counts update instantly with an optimistic UI (reverts cleanly if the server rejects the vote).

Polls can also have an **end date**: once expired, the poll shows results only and voting is disabled.

---

## 🏷️ Member Badges on Profiles

Every user's Flarum profile card now shows a **Groups** row at the bottom with a pill-shaped chip for each group they belong to — avatar (or colored initial), group name, and a direct link to that group. This works everywhere a profile card appears across the forum, not just on the groups pages. Private groups are hidden from viewers who aren't members.

---

## 🔁 Post Sharing

You can now **share a discussion into another group**. Open the ⋯ menu on any post card, choose **Share post**, pick a destination group from the picker (filtered to groups you're a member of), add an optional comment, and post. The shared discussion appears in the target group's feed with a **quoted card** showing the original group name, author, title, and content snippet — so the context is always clear.

---

## 🖼️ Media Gallery

Each group page now has a **Media tab** alongside the main discussion feed. It displays every image ever posted in the group — whether attached via fof/upload or embedded inline — in a responsive auto-fill grid. Click any thumbnail to open a **lightbox** with:

- Full-size image
- Previous / Next navigation (or keyboard arrow keys)
- Author name and avatar
- "View post" link back to the original discussion
- Image counter (e.g. "4 / 23")

Results are paginated at 24 per page.

---

## 📊 Group Analytics

Creators, moderators, and admins now see a collapsible **Analytics** panel in the group sidebar. It loads lazily on first expand and shows:

- **Summary stats** — total members, posts, and reactions at a glance
- **Member growth chart** — bar chart of new members per day for the last 30 days
- **Post volume chart** — bar chart of posts per week for the last 8 weeks
- **Top reacted posts** — the 5 most-reacted posts, each linking directly to the discussion

Charts are rendered as inline SVG — no external libraries, no extra HTTP requests.

---

## 🚫 Remove & Ban Members

Group creators and moderators can now **permanently remove** a member using the red remove button in the Members sidebar. Removed members are soft-banned — they cannot rejoin the group, and their membership record is preserved for audit purposes. The member count updates immediately.

---

## ⭐ Featured Groups

Admins can now **feature** any group directly from the group card's ⋯ menu. Featured groups appear in a dedicated highlighted section at the top of the `/groups` directory and are sorted first in API responses. Unfeature at any time with one click.

---

## 📡 Group RSS Feeds

Every public group now has a valid **RSS 2.0 feed** at `/groups/{slug}/feed.rss`, including the 20 most recent discussions with their titles, first-post content, author, and links. A small RSS icon in the group hero makes it easy for members to subscribe in their reader of choice. Private groups return a 403.

---

## 📌 Pinned Posts

Group creators and moderators can **pin any discussion** to the top of the feed from the ⋯ post menu. Pinned posts are sorted above all others and display a thumbtack badge. Pin and unpin at any time.

---

## 🔍 Post Search

A **search bar** now appears above the group feed. It filters discussions by title and first-post content as you type (400ms debounce). Clear the query with the × button to return to the full feed. Pagination stays accurate when searching.

---

## 👍 Emoji Reactions

Posts now support a **6-emoji reaction picker** (👍 ❤️ 😂 😮 😢 😡). Hover or tap the reaction button to open the picker, click an emoji to react, click again to remove it. The top 3 reactions and total count are shown in a stat bar below the post content. One reaction per user per post.

---

## 💬 Nested Replies

You can now **reply to a specific post** within a thread. A reply quote header shows who you're replying to, and the reply is visually indented under its parent. Nesting is limited to one level to keep threads readable — replying to a reply threads up to the root post automatically.

---

## 🔔 Notifications

Members now receive **in-app notifications** for activity in discussions they've participated in:

- **New post** — when a new top-level reply is added to a discussion you've previously commented in
- **Direct reply** — when someone replies specifically to your post

Notifications appear in Flarum's standard notification bell and link directly to the relevant discussion.

---

## 🔗 Link Previews

When you paste or type a URL in the group post composer, the extension **automatically fetches an Open Graph preview** in the background and attaches a preview card — site name, title, description, and image. You can dismiss a preview before posting if you don't want it included. Previews are stored with the post and rendered for all readers.

---

## Upgrading

```bash
composer update ernestdefoe/social-groups
php flarum migrate
php flarum cache:clear
```

No manual configuration changes are required for any of the new features. Polls, analytics, the media gallery, and member badges all activate automatically after migration.

---

## What's Next

The backlog still has a few ideas in the works. If you have feature requests, bug reports, or feedback on anything in 2.3.0, please reply below or open an issue on GitHub.

Thanks to everyone who has been testing and sending in reports — this release wouldn't have happened without you. Enjoy the update!
