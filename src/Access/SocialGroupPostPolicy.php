<?php

namespace Ernestdefoe\SocialGroups\Access;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Política de SocialGroupPost — consultada pelos
 * `Endpoint\Update->can('edit')` e `Endpoint\Delete->can('delete')`
 * em SocialGroupPostResource.
 *
 * Retornar `null` deixa o pipeline seguir e default-denies; use
 * `$this->allow()` para liberar explicitamente quando todas as
 * pré-condições passarem.
 */
class SocialGroupPostPolicy extends AbstractPolicy
{
    /**
     * Edit: somente o autor, e apenas se ele ainda for membro ativo
     * (não banido) do grupo. Admin global passa sempre.
     */
    public function edit(User $actor, SocialGroupPost $post)
    {
        if ($actor->isAdmin()) {
            return $this->allow();
        }
        if ((int) $actor->id !== (int) $post->user_id) {
            return null;
        }
        $group = $post->group;
        if ($group === null) {
            return null;
        }
        $active = $group->members()
            ->where('user_id', $actor->id)
            ->whereNull('banned_at')
            ->exists();
        return $active ? $this->allow() : null;
    }

    /**
     * Delete: autor, admin global, moderador global da extensão, ou
     * creator/moderator do próprio grupo.
     */
    public function delete(User $actor, SocialGroupPost $post)
    {
        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-social-groups.moderate')) {
            return $this->allow();
        }
        if ((int) $actor->id === (int) $post->user_id) {
            return $this->allow();
        }
        $group = $post->group;
        if ($group === null) {
            return null;
        }
        $isMod = $group->members()
            ->where('user_id', $actor->id)
            ->whereIn('role', ['creator', 'moderator'])
            ->exists();
        return $isMod ? $this->allow() : null;
    }
}
