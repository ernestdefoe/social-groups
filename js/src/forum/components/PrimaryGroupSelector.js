import { apiPost } from '../utils/api';
import { fetchUserGroups, invalidateUserGroups } from '../utils/userGroups';
import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * Shown in the user account settings page so members can choose
 * which social group displays as their badge on their profile.
 */
export default class PrimaryGroupSelector extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.groups   = null;
    this.loading  = true;
    this.saving   = false;
    this.selected = '';   // group id string, or '' for None
    this.error    = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.loadGroups();
  }

  loadGroups() {
    if (!app.session.user) { this.loading = false; return; }

    /*
     * Shared with the card badges and the profile Groups tab, which render on
     * the same page — read through the cache or the profile fetches the same
     * list twice. Copy the rows: `selected` is edited in place below.
     */
    fetchUserGroups(app.session.user.id()).then((groups) => {
      this.groups = groups.map((g) => ({ ...g }));
      // Restore saved selection from isPrimary flag returned by the API
      const primary = this.groups.find((g) => g.isPrimary);
      this.selected = primary ? String(primary.id) : '';
      this.loading  = false;
      m.redraw();
    });
  }

  save(groupId) {
    this.saving   = true;
    this.selected = groupId ? String(groupId) : '';
    this.error    = null;
    m.redraw();

    apiPost('/sg-primary-group', { groupId: groupId || null })
      .then(() => {
        // Reflect new isPrimary state locally so the badge updates without a reload
        if (this.groups) {
          this.groups.forEach((g) => { g.isPrimary = String(g.id) === this.selected; });
        }
        // The cached list (and the sgGroups already on the serialized user)
        // still carry the OLD isPrimary, so drop them — otherwise the next
        // reader shows the previous badge until a full reload.
        if (app.session.user) {
          invalidateUserGroups(app.session.user.id());
          app.session.user.pushAttributes({
            sgGroups: this.groups.map((g) => ({ ...g })),
          });
        }
        this.saving = false;
        m.redraw();
      })
      .catch((err) => {
        this.error  = err.response?.error || err.message || extractText(app.translator.trans('ernestdefoe-social-groups.forum.primary_group.save_failed'));
        this.saving = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading) {
      return m('.SG-PrimaryGroupSelector', m(LoadingIndicator, { size: 'small' }));
    }

    const groups = this.groups || [];

    return m('.SG-PrimaryGroupSelector', [
      m('.SG-PrimaryGroupSelector-header', [
        m('strong', app.translator.trans('ernestdefoe-social-groups.forum.primary_group.title')),
        m('span.helpText', app.translator.trans('ernestdefoe-social-groups.forum.primary_group.help')),
      ]),

      this.error ? m('.Alert.Alert--error', { style: 'margin:8px 0' }, this.error) : null,

      groups.length === 0
        ? m('em.SG-PrimaryGroupSelector-empty', app.translator.trans('ernestdefoe-social-groups.forum.primary_group.empty'))
        : m('.SG-PrimaryGroupSelector-grid', [
            m('label.SG-PrimaryGroupSelector-tile', { key: 'none', class: !this.selected ? 'active' : '' }, [
              m('input[type=radio].SG-PrimaryGroupSelector-radio', {
                name:     'sg-primary-group',
                value:    '',
                checked:  !this.selected,
                disabled: this.saving,
                onchange: () => this.save(null),
              }),
              m('span.SG-PrimaryGroupSelector-tileBadge.SG-PrimaryGroupSelector-tileBadge--none',
                m('i.fa-solid.fa-ban')),
              m('span.SG-PrimaryGroupSelector-tileName',
                app.translator.trans('ernestdefoe-social-groups.forum.primary_group.none')),
            ]),

            ...groups.map((group) =>
              m('label.SG-PrimaryGroupSelector-tile', {
                class: this.selected === String(group.id) ? 'active' : '',
                key:   group.id,
              }, [
                m('input[type=radio].SG-PrimaryGroupSelector-radio', {
                  name:     'sg-primary-group',
                  value:    String(group.id),
                  checked:  this.selected === String(group.id),
                  disabled: this.saving,
                  onchange: () => this.save(group.id),
                }),
                group.imageUrl
                  ? m('img.SG-PrimaryGroupSelector-tileImg', { src: group.imageUrl, alt: '' })
                  : m('span.SG-PrimaryGroupSelector-tileBadge', {
                      style: `background:${group.color || '#4A90E2'}`,
                    }, m('i.fa-solid.fa-users')),
                m('span.SG-PrimaryGroupSelector-tileName', group.name),
              ])
            ),
          ]),

      this.saving ? m('span.SG-PrimaryGroupSelector-saving', [m('i.fa-solid.fa-spinner.fa-spin'), ' ', app.translator.trans('ernestdefoe-social-groups.forum.primary_group.saving')]) : null,
    ]);
  }
}
