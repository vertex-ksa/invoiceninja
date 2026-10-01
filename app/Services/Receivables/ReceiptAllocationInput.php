<?php
declare(strict_types=1);
namespace App\Services\Receivables;
use InvalidArgumentException;
final class ReceiptAllocationInput
{
    public function validate(array $input, bool $accept): array
    {
        $keys = ['payment_id','invoice_id','amount_minor','expected_native_actor_id'];
        if ($accept) $keys = [...$keys, 'operation_key', 'expected_financial_beforeimage'];
        if (array_diff(array_keys($input), $keys) || array_diff($keys, array_keys($input))) {
            throw new InvalidArgumentException('Only the allocation command fields are accepted.');
        }
        foreach (['payment_id','invoice_id','expected_native_actor_id'] as $key) {
            if (!is_string($input[$key]) || !preg_match('/^[A-Za-z0-9]{1,128}$/D', $input[$key])) {
                throw new InvalidArgumentException('Native identity is required.');
            }
        }
        (new ExactAllocationAmounts())->amount($input['amount_minor']);
        if ($accept && (!is_string($input['operation_key']) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $input['operation_key'])
            || !is_string($input['expected_financial_beforeimage']) || !preg_match('/^[a-f0-9]{64}$/D', $input['expected_financial_beforeimage']))) {
            throw new InvalidArgumentException('An allocation operation identity and native financial beforeimage are required.');
        }
        return $input;
    }

    public function payloadHash(array $input): string
    {
        ksort($input);
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }
}
