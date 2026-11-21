<?php

namespace App\Support;

use App\Models\ConsumerUnit;

/** Oculta parcialmente dados da unidade para quem ainda não tem acesso a ela. */
class Mask
{
    /** "917.358.051-98" → "917.***.***-98". */
    public static function uc(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);
        if (strlen($digits) < 6) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 3).'.***.***-'.substr($digits, -2);
    }

    /** "RUA VANILSON JOAO, 65" → "RUA VAN*** JOA***, **". */
    public static function address(?string $address): string
    {
        if (! filled($address)) {
            return 'endereço não informado';
        }

        $parts = preg_split('/(\s+|,)/u', $address, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $words = 0;

        return collect($parts)->map(function (string $part) use (&$words) {
            if (preg_match('/^(\s+|,)$/u', $part)) {
                return $part;
            }
            if (preg_match('/\d/', $part)) {
                return str_repeat('*', mb_strlen($part));
            }

            return ++$words <= 1 ? $part : mb_substr($part, 0, 3).(mb_strlen($part) > 3 ? '***' : '');
        })->implode('');
    }

    public static function unit(ConsumerUnit $unit): string
    {
        return 'UC '.self::uc($unit->uc_number).' — '.self::address($unit->address ?: trim($unit->city.' '.$unit->neighborhood));
    }
}
