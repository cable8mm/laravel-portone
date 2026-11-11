<?php

declare(strict_types=1);

namespace Workbench\App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Cable8mm\LaravelPortone\Concerns\Billable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable;

    /**
     * Live tests mark a user paid at most once, as an order would be, by this e-mail domain (EL-069).
     */
    public const SINGLE_PAYMENT_EMAIL_DOMAIN = '@single-payment.workbench.test';

    public function allowsOnlyOnePaidPayment(): bool
    {
        return str_ends_with((string) $this->email, self::SINGLE_PAYMENT_EMAIL_DOMAIN);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
