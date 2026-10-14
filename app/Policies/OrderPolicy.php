<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Determine whether the user can view the order.
     *
     * Visible to the buyer, the organizer of the event and admins.
     */
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id
            || $order->event->isOrganizedBy($user)
            || $user->isAdmin();
    }

    /**
     * Determine whether the user can view the complete page of the order.
     *
     * Only the buyer, since the page is shown right after their own purchase.
     */
    public function viewComplete(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }

    /**
     * Determine whether the user can cancel the order.
     *
     * Only the buyer, who comes back here from the Stripe payment page.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }
}
