import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

const label = (key) => extractText(app.translator.trans(`ernestdefoe-social-groups.forum.reactions.${key}`));

/**
 * The reactions a member can pick. Keys are what the server stores; `heart`
 * is labelled from the `love` translation.
 */
export const REACTIONS = [
  { key: 'like', emoji: '👍', labelKey: 'like' },
  { key: 'heart', emoji: '❤️', labelKey: 'love' },
  { key: 'haha', emoji: '😂', labelKey: 'haha' },
  { key: 'wow', emoji: '😮', labelKey: 'wow' },
  { key: 'sad', emoji: '😢', labelKey: 'sad' },
  { key: 'angry', emoji: '😡', labelKey: 'angry' },
].map((r) => ({
  ...r,
  get label() {
    return label(r.labelKey);
  },
}));
