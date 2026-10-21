<?php
declare(strict_types=1);
namespace Amyx\Anvil\rules;

final class AnvilWear{
    /** 0 = intact, 1 = chipped, 2 = damaged, 3 = broken. */
    public static function afterUse(int $damage,bool $creative,int $roll): int{
        if($damage<0 || $damage>2 || $roll<1 || $roll>100){ throw new \InvalidArgumentException('Invalid anvil wear state'); }
        return !$creative && $roll<=12 ? $damage+1 : $damage;
    }
}
