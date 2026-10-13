<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerRegistry
{
    public function has(Model $billable): bool
    {
        return $this->query($billable)->exists();
    }

    public function findId(Model $billable): ?string
    {
        $customerId = $this->query($billable)->value('customer_id');

        return is_string($customerId) ? $customerId : null;
    }

    public function ensureId(Model $billable): string
    {
        if (($customerId = $this->findId($billable)) !== null) {
            return $customerId;
        }

        try {
            $customer = DB::transaction(fn (): Customer => Customer::query()->create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $this->billableId($billable),
                'customer_id' => (string) Str::uuid(),
            ]));

            $customerId = $customer->getAttribute('customer_id');

            if (! is_string($customerId)) {
                throw new \UnexpectedValueException('The created Customer record has no Customer ID.');
            }

            return $customerId;
        } catch (QueryException $exception) {
            $customerId = DB::table('portone_customers')
                ->where('billable_type', $billable->getMorphClass())
                ->where('billable_id', $this->billableId($billable))
                ->value('customer_id');

            if (is_string($customerId)) {
                return $customerId;
            }

            throw $exception;
        }
    }

    /** @return Builder<Customer> */
    private function query(Model $billable): Builder
    {
        return Customer::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $this->billableId($billable));
    }

    private function billableId(Model $billable): string
    {
        $key = $billable->getKey();

        if (! is_string($key) && ! is_int($key)) {
            throw new \InvalidArgumentException('A Billable model must have a string or integer key.');
        }

        return (string) $key;
    }
}
