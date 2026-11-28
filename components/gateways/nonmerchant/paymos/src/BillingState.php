<?php

declare(strict_types=1);

namespace PaymosBlesta;

use Paymos\Plugin\AmountGuard;

/**
 * Current Blesta state of the invoices one Paymos payment covers.
 *
 * The webhook (gateway) and the cron reconciler (companion plugin) must judge a payment
 * the same way, so both read it here: the sum of `due` across every invoice of the
 * payment, whether all of them are closed, and their common currency.
 */
final class BillingState
{
    public const CURRENCY_MISMATCH = '__CURRENCY_MISMATCH__';

    /**
     * @param array<string, mixed> $snapshot
     * @param callable $loadInvoice function (int $invoiceId): object|false|null — Blesta Invoices::get()
     * @return array<string, mixed> Empty when no invoice is known; otherwise deleted/paid/amount/currency.
     */
    public static function fromInvoices(array $snapshot, callable $loadInvoice): array
    {
        $ids = SnapshotStore::invoiceIds($snapshot);
        if (count($ids) === 0) {
            return array();
        }

        $allPaid = true;
        $currency = '';
        $currencyMismatch = false;
        $dueAmount = '0';
        $loadedCount = 0;

        foreach ($ids as $id) {
            $invoice = call_user_func($loadInvoice, $id);
            if (!is_object($invoice)) {
                $allPaid = false;
                continue;
            }
            $loadedCount++;

            $due = isset($invoice->due) ? AmountGuard::normalizeDecimal($invoice->due) : null;
            $isClosed = !empty($invoice->date_closed) || ($due !== null && ($due === '0' || $due[0] === '-'));
            if (!$isClosed) {
                $allPaid = false;
            }

            if (isset($invoice->currency) && is_string($invoice->currency)) {
                $invoiceCurrency = strtoupper(trim($invoice->currency));
                if ($invoiceCurrency !== '') {
                    if ($currency === '') {
                        $currency = $invoiceCurrency;
                    } elseif ($currency !== $invoiceCurrency) {
                        $currencyMismatch = true;
                    }
                }
            }

            $rawBalance = isset($invoice->due) ? $invoice->due : (isset($invoice->total) ? $invoice->total : null);
            if ($rawBalance !== null) {
                $normalized = AmountGuard::normalizeDecimal($rawBalance);
                if ($normalized !== null) {
                    $dueAmount = self::addDecimals($dueAmount, $normalized);
                }
            }
        }

        if ($loadedCount === 0) {
            return array('deleted' => true, 'paid' => false);
        }

        if ($currencyMismatch) {
            return array(
                'deleted' => false,
                'paid' => false,
                'amount' => $dueAmount,
                'currency' => self::CURRENCY_MISMATCH,
            );
        }

        $normalizedDue = AmountGuard::normalizeDecimal($dueAmount);
        return array(
            'deleted' => false,
            'paid' => $allPaid,
            'amount' => $normalizedDue !== null ? $normalizedDue : (string) ($snapshot['amount'] ?? ''),
            'currency' => $currency !== '' ? $currency : strtoupper(trim((string) ($snapshot['currency'] ?? ''))),
        );
    }

    /**
     * Exact arbitrary-precision decimal addition (no IEEE-754 float rounding).
     */
    public static function addDecimals(string $left, string $right): string
    {
        $a = AmountGuard::normalizeDecimal($left);
        $b = AmountGuard::normalizeDecimal($right);
        if ($a === null || $b === null) {
            throw new \InvalidArgumentException('Invalid decimal operand for exact addition.');
        }

        $aNeg = $a[0] === '-';
        $bNeg = $b[0] === '-';
        $aAbs = $aNeg ? substr($a, 1) : $a;
        $bAbs = $bNeg ? substr($b, 1) : $b;

        $aParts = explode('.', $aAbs, 2);
        $bParts = explode('.', $bAbs, 2);
        $aFrac = $aParts[1] ?? '';
        $bFrac = $bParts[1] ?? '';
        $scale = max(strlen($aFrac), strlen($bFrac));

        $aInt = ltrim($aParts[0] . str_pad($aFrac, $scale, '0'), '0');
        $bInt = ltrim($bParts[0] . str_pad($bFrac, $scale, '0'), '0');
        $aInt = $aInt === '' ? '0' : $aInt;
        $bInt = $bInt === '' ? '0' : $bInt;

        if ($aNeg === $bNeg) {
            $digits = self::addDigitStrings($aInt, $bInt);
            $neg = $aNeg;
        } else {
            $cmp = self::compareDigitStrings($aInt, $bInt);
            if ($cmp === 0) {
                return '0';
            }
            if ($cmp > 0) {
                $digits = self::subtractDigitStrings($aInt, $bInt);
                $neg = $aNeg;
            } else {
                $digits = self::subtractDigitStrings($bInt, $aInt);
                $neg = $bNeg;
            }
        }

        if ($scale > 0) {
            if (strlen($digits) <= $scale) {
                $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            }
            $splitPos = strlen($digits) - $scale;
            $formatted = substr($digits, 0, $splitPos) . '.' . substr($digits, $splitPos);
        } else {
            $formatted = $digits;
        }

        $result = AmountGuard::normalizeDecimal(($neg ? '-' : '') . $formatted);
        return $result !== null ? $result : '0';
    }

    private static function addDigitStrings(string $a, string $b): string
    {
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $carry = 0;
        $out = '';

        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += ord($a[$i]) - 48;
                $i--;
            }
            if ($j >= 0) {
                $sum += ord($b[$j]) - 48;
                $j--;
            }
            $out = (string) ($sum % 10) . $out;
            $carry = (int) floor($sum / 10);
        }

        $trimmed = ltrim($out, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function compareDigitStrings(string $a, string $b): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA !== $lenB) {
            return $lenA < $lenB ? -1 : 1;
        }
        return strcmp($a, $b);
    }

    private static function subtractDigitStrings(string $larger, string $smaller): string
    {
        $i = strlen($larger) - 1;
        $j = strlen($smaller) - 1;
        $borrow = 0;
        $out = '';

        while ($i >= 0) {
            $digit = (ord($larger[$i]) - 48) - $borrow;
            if ($j >= 0) {
                $digit -= (ord($smaller[$j]) - 48);
                $j--;
            }
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out = (string) $digit . $out;
            $i--;
        }

        $trimmed = ltrim($out, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }
}
