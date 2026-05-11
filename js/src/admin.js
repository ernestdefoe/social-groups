import app from 'flarum/admin/app';
import extenders from './extend';

app.initializers.add('ernestdefoe-social-groups', () => {
  app.extensionData
    .for('ernestdefoe-social-groups')
    .registerPermission(
      {
        icon: 'fas fa-users',
        label: app.translator.trans('ernestdefoe-social-groups.admin.permissions.create_groups'),
        permission: 'ernestdefoe-social-groups.create',
      },
      'start',
      90
    )
    .registerPermission(
      {
        icon: 'fas fa-shield-alt',
        label: app.translator.trans('ernestdefoe-social-groups.admin.permissions.moderate_groups'),
        permission: 'ernestdefoe-social-groups.moderate',
      },
      'moderate',
      90
    );
});

export default extenders;
