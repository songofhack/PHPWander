<?php declare(strict_types=1);

namespace PHPWander\Analyser;

use PHPCfg\Op;
use PHPCfg\Op\Expr\FuncCall;
use PHPCfg\Op\Expr\PropertyFetch;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;

/**
 * @author Pavel Jurásek
 */
class Helpers
{

	public static function unwrapOperand(Operand $operand): string
	{
		if ($operand instanceof Operand\Temporary) {
			if ($operand->original !== null) {
				return self::unwrapOperand($operand->original);
			}

//			if ($operand->ops[0] instanceof PropertyFetch) {
//
//			}

			foreach ($operand->ops as $op) {
				return self::unwrapOp($op);
			}

			return self::unwrapOperand($operand->ops[0]->name);
		} elseif ($operand instanceof Literal) {
			return self::unwrapLiteral($operand);
		} elseif ($operand instanceof Operand\Variable) {
			return self::unwrapOperand($operand->name);
		}

//		$name = '?';
		return $operand->name;
	}

	public static function unwrapOp(Op $op): string
	{
		if ($op instanceof PropertyFetch) {
			return sprintf('$%s->%s', self::unwrapOperand($op->var), self::unwrapOperand($op->name));
		}

		if ($op instanceof FuncCall) {
			return sprintf('%s(%s)', self::unwrapOperand($op->name), self::unwrapList($op->args));
		}

		if ($op instanceof Op\Expr\MethodCall) {
			return sprintf('$%s->%s(%s)', self::unwrapOperand($op->var), self::unwrapOperand($op->name), self::unwrapList($op->args));
		}

		if ($op instanceof Op\Expr\Assign) {
			return sprintf('%s = %s', self::unwrapOperand($op->var), self::unwrapOperand($op->expr));
		}

		if ($op instanceof Op\Expr\ConcatList) {
			return self::unwrapList($op->list);
		}

		if ($op instanceof Op\Expr\Cast) {
			return self::unwrapCast($op);
		}

		dump($op);
		dump(__METHOD__);

		return '?';
	}

	public static function unwrapLiteral(Literal $literal): string
	{
		return $literal->value;
	}

	/** @param Operand[] $operands */
	private static function unwrapList(array $operands)
	{
		return implode(', ', array_map('self::unwrapOperand', $operands));
	}

	private static function unwrapCast(Op\Expr\Cast $op): string
	{
		$class = get_class($op);
		$cast = strtolower(rtrim(substr($class, strrpos($class, '\\')), '_'));

		return sprintf('(%s)', $cast);
	}

}