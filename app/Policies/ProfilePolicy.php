<?php

namespace App\Policies;

use App\Models\Profile;
use App\Models\User;

class ProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->actif && $user->gereLesUtilisateurs();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Profile $profile): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Un profil système ou encore attribué à quelqu'un ne se supprime pas.
     */
    public function delete(User $user, Profile $profile): bool
    {
        return $this->viewAny($user) && $profile->supprimable();
    }
}
