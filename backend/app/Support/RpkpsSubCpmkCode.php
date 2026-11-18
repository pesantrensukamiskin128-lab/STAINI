<?php

namespace App\Support;

class RpkpsSubCpmkCode
{
    public static function make(int $cpmkOrder, int $subCpmkOrder): string
    {
        return "Sub-{$cpmkOrder}.{$subCpmkOrder}";
    }
}
