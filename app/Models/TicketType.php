<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Services\OrderService;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $name
 * @property int $price
 * @property int $capacity
 * @property Carbon $sales_start_at
 * @property Carbon $sales_end_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'price', 'capacity', 'sales_start_at', 'sales_end_at'])]
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory;

    /**
     * Minutes a pending order keeps its tickets reserved.
     */
    public const PENDING_MINUTES = OrderService::PENDING_MINUTES;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'capacity' => 'integer',
            'sales_start_at' => 'datetime',
            'sales_end_at' => 'datetime',
        ];
    }

    /**
     * Get the event the ticket type belongs to.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the order items for the ticket type.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Scope a query to include the reserved quantity as `reserved_quantity`.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withReservedQuantity(Builder $query): void
    {
        $query->withSum(['orderItems as reserved_quantity' => function (Builder $query) {
            $query->whereHas('order', function (Builder $query) {
                $query->where('status', OrderStatus::Paid)
                    ->orWhere(function (Builder $query) {
                        $query->where('status', OrderStatus::Pending)
                            ->where('created_at', '>=', now()->subMinutes(self::PENDING_MINUTES));
                    });
            });
        }], 'quantity');
    }

    /**
     * Get the number of tickets still available.
     *
     * Uses `reserved_quantity` when loaded by withReservedQuantity() to avoid N+1 queries.
     */
    public function remaining(): int
    {
        $reserved = array_key_exists('reserved_quantity', $this->attributes)
            ? (int) $this->attributes['reserved_quantity']
            : (int) self::query()->whereKey($this->id)->withReservedQuantity()->value('reserved_quantity');

        return max(0, $this->capacity - $reserved);
    }

    /**
     * Determine if the ticket type is within its sales period.
     */
    public function isOnSale(): bool
    {
        return now()->between($this->sales_start_at, $this->sales_end_at);
    }
}
