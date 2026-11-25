<?php
declare(strict_types=1);

namespace CheckoApi\Registry;

final class Inn
{
    public static function isValid(string $value, EntityType $type): bool
    {
        if (strlen($value) !== $type->innLength() || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return false;
        }

        // У организации один контрольный разряд, у предпринимателя их два.
        $weights = match ($type) {
            EntityType::Company => [[2, 4, 10, 3, 5, 9, 4, 6, 8]],
            EntityType::Entrepreneur => [[7, 2, 4, 10, 3, 5, 9, 4, 6, 8], [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]],
        };
        foreach ($weights as $coefficients) {
            $sum = 0;
            foreach ($coefficients as $position => $coefficient) {
                $sum += (int) $value[$position] * $coefficient;
            }
            if ((int) $value[count($coefficients)] !== $sum % 11 % 10) {
                return false;
            }
        }
        return true;
    }
}
