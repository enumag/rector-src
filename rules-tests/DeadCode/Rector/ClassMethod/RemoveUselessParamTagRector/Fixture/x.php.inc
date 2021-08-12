<?php

declare(strict_types = 1);

namespace Rector\Tests\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector\Fixture;

class X {
	/**
	 * @param int[] $list
	 * @param Closure(int): bool $filter
	 *
	 * @return int[]
	 */
	static function filterNumbers(array $list, Closure $filter): array {
		$result = [];
		foreach ($list as $value) {
			if ($filter($value)) {
				$result[] = $value;
			}
		}

		return $result;
	}
}

X::filterNumbers([1], fn (string $x) => $x > 0);
?>
