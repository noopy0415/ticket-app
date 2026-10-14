<?php

namespace App\Policies;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /**
     * Determine whether the user can view the event.
     *
     * Published events are visible to anyone, including guests.
     * Other events are visible only to the organizer and admins.
     */
    public function view(?User $user, Event $event): bool
    {
        if ($event->isPublished()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $event->isOrganizedBy($user) || $user->isAdmin();
    }

    /**
     * Determine whether the user can create events.
     */
    public function create(User $user): bool
    {
        return $user->isOrganizer();
    }

    /**
     * Determine whether the user can update the event.
     */
    public function update(User $user, Event $event): bool
    {
        return $event->isOrganizedBy($user);
    }

    /**
     * Determine whether the user can switch the event between draft and published.
     *
     * Events unpublished by an admin cannot be switched by the organizer.
     */
    public function publish(User $user, Event $event): bool
    {
        return $event->isOrganizedBy($user) && $event->status !== EventStatus::Unpublished;
    }

    /**
     * Determine whether the user can delete the event.
     */
    public function delete(User $user, Event $event): bool
    {
        return $event->isOrganizedBy($user);
    }

    /**
     * Determine whether the user can unpublish the event as an admin.
     */
    public function unpublish(User $user, Event $event): bool
    {
        return $user->isAdmin();
    }
}
