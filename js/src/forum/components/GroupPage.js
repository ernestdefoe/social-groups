import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import GroupHero from './GroupHero';
import GroupFeed from './GroupFeed';
import GroupMediaGallery from './GroupMediaGallery';
import GroupAnalyticsPanel from './GroupAnalyticsPanel';
import MemberList from './MemberList';
import JoinRequestsPanel from './JoinRequestsPanel';
import EditGroupModal from './EditGroupModal';
import SGSkeleton from './SGSkeleton';

const t = (key) => app.translator.trans(`ernestdefoe-social-groups.forum.${key}`);

/**
 * A group's page: the hero, the feed or media tab, and the sidebar. What the
 * actor may manage comes from the group's own `canManage` and `canEdit`.
 */
export default class GroupPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.tab = 'posts';
    this.load();
  }

  onupdate() {
    if (this.loadedFor !== this.attrs.slug) this.load();
  }

  /**
   * An unknown or private group is an expected state here, shown inline
   * rather than as core's error alert.
   */
  load() {
    this.loadedFor = this.attrs.slug;
    this.group = null;
    this.missing = false;

    app.store
      .find('social-groups', this.attrs.slug, { include: 'user' }, { errorHandler: () => {} })
      .then((group) => {
        this.group = group;
        app.setTitle(group.name());
      })
      .catch(() => (this.missing = true))
      .finally(() => m.redraw());
  }

  view() {
    if (this.missing) return m('.GroupPage', m('.container', m('p.GroupPage-error', t('group.not_found'))));
    if (!this.group) return m('.GroupPage', m(SGSkeleton, { surface: 'group', fallback: 520, rows: 5 }));

    const group = this.group;
    const approval = group.membershipType() === 'approval';

    return m('.GroupPage', [
      m(GroupHero, { group, onEdit: () => app.modal.show(EditGroupModal, { group }) }),
      m('.GroupPage-body', [
        m('.GroupPage-main', [
          m('.GroupPage-tabs', [
            this.tabButton('posts', 'fa-bars-staggered', 'group.tab_posts'),
            this.tabButton('media', 'fa-photo-film', 'group.tab_media'),
          ]),
          this.tab === 'media'
            ? m(GroupMediaGallery, { groupId: group.id(), groupSlug: group.slug(), isMember: group.isMember() })
            : m(GroupFeed, { group }),
        ]),
        m('.GroupPage-sidebar', [
          approval && group.attribute('canManage') ? m(JoinRequestsPanel, { group }) : null,
          m('.GroupPage-aboutCard', [
            m('.GroupPage-aboutCard-title', t('group.about_title')),
            group.description()
              ? m('p.GroupPage-aboutCard-text', group.description())
              : m('p.GroupPage-aboutCard-empty', t('group.description_placeholder')),
            group.isPrivate() ? m('.GroupPage-privateTag', [m('i.fa-solid.fa-lock'), ' ', t('groups.private')]) : null,
            approval ? m('.GroupPage-approvalTag', [m('i.fa-solid.fa-user-check'), ' ', t('groups.approval_required')]) : null,
          ]),
          group.attribute('canManage') ? m(GroupAnalyticsPanel, { groupId: group.id(), groupSlug: group.slug() }) : null,
          m(MemberList, { group }),
        ]),
      ]),
    ]);
  }

  tabButton(tab, icon, label) {
    return m('button.GroupPage-tab', { type: 'button', className: this.tab === tab ? 'is-active' : '', onclick: () => (this.tab = tab) }, [
      m(`i.fa-solid.${icon}`),
      ' ',
      t(label),
    ]);
  }
}
