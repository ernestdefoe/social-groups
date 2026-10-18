import app from 'flarum/forum/app';
import { MentionDropdown } from '../components/feed/MentionDropdown';

/**
 * @-mention autocomplete for a group's composers. Each composer passes itself
 * as the owner: the dropdown shows under the one being typed in, and the
 * chosen member is spliced back through its `setText`. The group's members
 * load once, on the first @.
 */
export default class MentionTracker {
  constructor(groupId) {
    this.groupId = groupId;
    this.members = null;
    this.loading = false;
    this.owner = null;
    this.query = null;
    this.textarea = null;
    this.onDocumentClick = (e) => {
      if (this.query !== null && !e.target.closest?.('.SGFeed-mentionDropdown') && e.target !== this.textarea && this.close()) m.redraw();
    };
    document.addEventListener('click', this.onDocumentClick);
  }

  destroy() {
    document.removeEventListener('click', this.onDocumentClick);
  }

  onInput(owner, e) {
    const match = e.target.value.slice(0, e.target.selectionStart).match(/@([\w-]*)$/);

    if (match) {
      this.owner = owner;
      this.query = match[1];
      this.textarea = e.target;
      this.load();
    } else if (this.owner === owner) {
      this.close();
    }
  }

  viewDropdown(owner) {
    if (this.owner !== owner) return null;

    return MentionDropdown({ members: this.members, loading: this.loading, query: this.query, onSelect: (member) => this.select(member) });
  }

  close() {
    if (this.query === null) return false;
    this.owner = null;
    this.query = null;

    return true;
  }

  load() {
    if (this.members !== null || this.loading) return;
    this.loading = true;

    app.store
      .find('social-group-members', { groupId: this.groupId, include: 'user' })
      .then((rows) => {
        this.members = rows.map((r) => ({
          userId: r.attribute('userId'),
          displayName: r.attribute('displayName') || '',
          avatarUrl: r.attribute('avatarUrl'),
          slug: r.attribute('slug') || '',
          role: r.attribute('role'),
        }));
      })
      .catch(() => (this.members = []))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /**
   * Inserts `@username`, which flarum/mentions links and which still reads
   * as a name without it.
   */
  select(member) {
    const ta = this.textarea;
    const owner = this.owner;
    const caret = ta.selectionStart;
    const match = ta.value.slice(0, caret).match(/@([\w-]*)$/);
    if (!match || !owner) return this.close();

    const start = caret - match[0].length;
    const inserted = `@${member.slug || member.displayName} `;
    const text = ta.value.slice(0, start) + inserted + ta.value.slice(caret);

    owner.setText(text);
    this.close();
    m.redraw();

    requestAnimationFrame(() => {
      if (!ta.isConnected) return;
      ta.value = text;
      ta.focus();
      ta.setSelectionRange(start + inserted.length, start + inserted.length);
    });
  }
}
