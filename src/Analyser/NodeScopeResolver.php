<?php declare(strict_types = 1);

namespace PHPWander\Analyser;

use Nette\InvalidStateException;
use PHPCfg\Block;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCfg\Op\Expr\ArrayDimFetch;
use PHPCfg\Op\Expr\Assign;
use PHPCfg\Op\Expr\BinaryOp;
use PHPWander\Broker\Broker;
use PHPStan\File\FileHelper;
use PHPWander\Taint;
use PHPWander\TransitionFunction;

class NodeScopeResolver
{

	/** @var TransitionFunction */
	private $transitionFunction;

	/** @var \PHPWander\Broker\Broker */
	private $broker;

	/** @var \PHPWander\Parser\Parser */
	private $parser;

	/** @var \PHPStan\File\FileHelper */
	private $fileHelper;

	/** @var bool */
	private $polluteScopeWithLoopInitialAssignments;

	/** @var bool */
	private $polluteCatchScopeWithTryAssignments;

	/** @var string[][] className(string) => methods(string[]) */
	private $earlyTerminatingMethodCalls;

	/** @var bool[] filePath(string) => bool(true) */
	private $analysedFiles;

	/** @var Func[] */
	private $functions;

	/** @var FuncCallMapping[] */
	private $funcCallMappings = [];

	/** @var Block[] */
	private $visitedBlocks = [];

	public function __construct(
		Broker $broker,
		TransitionFunction $transitionFunction,
		\PHPWander\Parser\Parser $parser,
		FileHelper $fileHelper,
		bool $polluteScopeWithLoopInitialAssignments = true,
		bool $polluteCatchScopeWithTryAssignments = false,
		array $earlyTerminatingMethodCalls = []
	)
	{
		$this->broker = $broker;
		$this->transitionFunction = $transitionFunction;
		$this->parser = $parser;
		$this->fileHelper = $fileHelper;
		$this->polluteScopeWithLoopInitialAssignments = $polluteScopeWithLoopInitialAssignments;
		$this->polluteCatchScopeWithTryAssignments = $polluteCatchScopeWithTryAssignments;
		$this->earlyTerminatingMethodCalls = $earlyTerminatingMethodCalls;
		$this->functions = [];
	}

	/** @param string[] $files */
	public function setAnalysedFiles(array $files): void
	{
		$this->analysedFiles = array_fill_keys($files, true);
	}

	public function processScript(
		Script $script,
		Scope $scope,
		\Closure $opCallback
	): Scope {
		foreach ($script->functions as $function) {
			if (array_key_exists($function->name, $this->functions)) {
				throw new InvalidStateException(sprintf('Cannot redeclare a function %s.', $function->name));
			}

			$this->functions[$function->name] = $function;
		}

		$scope = $this->processNodes($script->main->cfg->children, $scope, $opCallback);

		return $scope;
	}

	private function processBlock(Block $block, Scope $scope, \Closure $opCallback): Scope {
		if (in_array($block, $this->visitedBlocks)) {
			return $scope;
		}

		$this->visitedBlocks[] = $block;

		return $this->processNodes($block->children, $scope, $opCallback);
	}

	/**
	 * @param Op[] $nodes
	 */
	public function processNodes(array $nodes, Scope $scope, \Closure $opCallback): Scope {
		foreach ($nodes as $i => $op) {
			$scope = $this->processNode($op, $scope, $opCallback);
		}

		return $scope;
	}

	private function processNode(Op $op, Scope $scope, \Closure $nodeCallback): Scope
	{
		if ($op instanceof Op\Expr\New_) {
			$name = Helpers::unwrapOperand($op->class);
			$op->setAttribute('type', $name);

		} elseif ($op instanceof Op\Stmt\Jump) {
			$scope = $this->processBlock($op->target, $scope, $nodeCallback);

		} elseif ($op instanceof Op\Expr\Include_) {
			$scope = $this->processInclude($scope, $op, $nodeCallback);

		} elseif ($op instanceof Op\Stmt\JumpIf) {
//			$this->processIf($op->cond, $scope, $nodeCallback);
			$scope = $this->processNodes($op->if->children, $scope, $nodeCallback);

		} elseif ($op instanceof Op\Stmt\Function_) {
//			$scope = $this->enterFunction($scope, $op);

		} elseif ($op instanceof Op\Expr\Closure) {

		} elseif ($op instanceof Assign) {
			$scope = $this->processAssign($scope, $op);

		} elseif ($op instanceof Op\Expr\FuncCall) {
			$funcName = Helpers::unwrapOperand($op->name);
			if (array_key_exists($funcName, $this->functions)) {
				$this->processFunction($this->functions[$funcName], $op, $scope, $nodeCallback);
			} else {
				$taint = $this->transitionFunction->transferOp($scope, $op);
				$op->setAttribute(Taint::ATTR, $taint);
			}

		} elseif ($op instanceof ArrayDimFetch) {
			$this->processArrayFetch($op, $scope);

		} elseif ($op instanceof BinaryOp\Concat) {
			$taint = $this->transitionFunction->leastUpperBound(
				$this->transitionFunction->transfer($scope, $op->left),
				$this->transitionFunction->transfer($scope, $op->right)
			);
			$op->setAttribute(Taint::ATTR, $taint);
		} elseif ($op instanceof Op\Expr\ConcatList) {
			$taint = Taint::UNKNOWN;
			foreach ($op->list as $part) {
				$taint = $this->transitionFunction->leastUpperBound($taint, $this->transitionFunction->transfer($scope, $part));
			}
			$op->setAttribute(Taint::ATTR, $taint);
		} elseif ($op instanceof Op\Terminal\Return_) {
			$taint = $this->transitionFunction->transferOp($scope, $op, true);
			$op->setAttribute(Taint::ATTR, $taint);

//			if (!$scope->isInClass() && $scope->getFunction() === null) {
//				$scope->setResultTaint($taint);
//			}
		} elseif ($op instanceof Op\Expr\Cast) {
			$scope = $this->transitionFunction->transferCast($scope, $op);
		}

		$nodeCallback($op, $scope);

		return $scope;
	}

	private function processAssign(Scope $scope, Assign $op): Scope
	{
		$name = Helpers::unwrapOperand($op->var);

		if ($op->expr instanceof Operand\Temporary) {
			foreach ($op->expr->ops as $_op) {
				if ($_op instanceof Op\Expr\Closure) {
					$this->functions[$name] = &$this->functions[$_op->func->name];
				} elseif ($_op instanceof Op\Expr\New_) {
					$type = $_op->getAttribute('type');
					$op->setAttribute('type', $type);
				}
			}
		}

		$taint = $this->transitionFunction->transfer($scope, $op->expr);
		$op->setAttribute(Taint::ATTR, $taint);
		$scope = $scope->assignVariable($name, $taint);
//		taint($scope->getVariableTaints());

		return $scope;
	}

	private function processArrayFetch(ArrayDimFetch $op, Scope $scope): Scope
	{
		if ($op->var instanceof Operand\Temporary) {
			if ($op->var->original instanceof Operand\Variable) {
				/** @var Operand\Variable $variable */
				$variable = $op->var->original;

				if ($this->transitionFunction->isSuperGlobal($variable)) {
					$taint = $this->transitionFunction->transferSuperGlobal($variable, $this->unpackExpression($op->dim));
					$op->setAttribute(Taint::ATTR, $taint);
				} else {
					$taint = $this->transitionFunction->transfer($scope, $variable);
					$op->setAttribute(Taint::ATTR, $taint);
				}

//				$op->result->setAttribute(Taint::ATTR, $taint);
			} else {
				dump(__METHOD__);
				dump($op->var->original);
				die;
			}
		}

		return $scope;
	}

	private function processFunction(Func $function, Op\Expr\FuncCall $call, Scope $scope, \Closure $nodeCallback)
	{
		$bindArgs = [];

		foreach ($function->params as $i => $param) {
			/** @var Operand $arg */
			$arg = $call->args[$i];

			if ($arg instanceof Operand\Temporary && $arg->original instanceof Operand\Variable) {
				$scope = $scope->assignVariable(Helpers::unwrapOperand($param->name), $scope->getVariableTaint(Helpers::unwrapOperand($arg)));
				$bindArgs[Helpers::unwrapOperand($param->name)] = $scope->getVariableTaint(Helpers::unwrapOperand($arg));
			} else { // func call?
				$scope = $scope->assignVariable(Helpers::unwrapOperand($param->name), $this->lookForFuncCalls($arg));
				$bindArgs[Helpers::unwrapOperand($param->name)] = $this->lookForFuncCalls($arg);
			}
		}

		$mapping = $this->findFuncCallMapping($function, $bindArgs);
		if ($mapping !== null) {
			$taint = $mapping->getTaint();
		} else {
			$this->processNodes($function->cfg->children, $scope, $nodeCallback);

			$taint = Taint::UNKNOWN;
			foreach ($function->cfg->children as $op) {
				if ($op instanceof Op\Terminal\Return_) {
					$taint = $this->transitionFunction->leastUpperBound($taint, (int) $op->getAttribute(Taint::ATTR));
				}
			}

			$this->funcCallMappings[] = new FuncCallMapping($function, $bindArgs, $taint);
		}

		$call->setAttribute(Taint::ATTR, $taint);
	}

	private function lookForFuncCalls(Operand\Temporary $arg): int
	{
		$taint = Taint::UNKNOWN;
		if ($arg->original === null) {
			/** @var Op $op */
			foreach ($arg->ops as $op) {
				$taint = $this->transitionFunction->leastUpperBound($taint, (int) $op->getAttribute(Taint::ATTR));
			}
		}

		return $taint;
	}

	private function processInclude(Scope $scope, Op\Expr\Include_ $op, \Closure $nodeCallback): Scope
	{
		if ($op->expr instanceof Operand\Temporary) {
			if ($this->isExprResolvable($op->expr)) {
				$file = $this->resolveIncludedFile($op->expr);

				if (is_file($file) && $file !== $scope->getFile()) {
					$scriptScope = $this->processScript(
						$this->parser->parseFile($file),
						$scope->enterFile($file),
						$nodeCallback
					);

					$taint = $scope->getResultTaint();
					$threats = ['result'];

					$op->setAttribute(Taint::ATTR, $taint);
					$op->setAttribute(Taint::ATTR_THREATS, $threats);
				}
			} elseif ($this->isSafeForFileInclusion($op->expr)) {
				$taint = Taint::UNTAINTED;
				$threats = ['file'];

				$op->setAttribute(Taint::ATTR, $taint);
				$op->setAttribute(Taint::ATTR_THREATS, $threats);

			} else {
				$taint = Taint::TAINTED;
				$threats = ['file'];

				$op->setAttribute(Taint::ATTR, $taint);
				$op->setAttribute(Taint::ATTR_THREATS, $threats);
			}

			return $scope;
		}

		dump(__FUNCTION__);
		dump($scope);
		die;

		return $scope;
	}

	private function resolveIncludedFile(Operand\Temporary $expr): string
	{
		if (!empty($expr->ops)) {
			return $this->unpackExpression($expr->ops[0]);
		}

		dump('?');
		dump($expr);
		die;

		return '?';
	}

	private function unpackExpression($expr): string
	{
		if ($expr instanceof BinaryOp\Concat) {
			return $this->unpackExpression($expr->left) . $this->unpackExpression($expr->right);
		} elseif ($expr instanceof Assign) {
			return $this->unpackExpression($expr->expr);
		} elseif ($expr instanceof Operand\Temporary) {
			if (!empty($expr->ops)) {
				return $this->unpackExpression($expr->ops[0]);
			}

			return $this->unpackExpression($expr->original);
		} elseif ($expr instanceof Operand) {
			return Helpers::unwrapOperand($expr);
		}

		dump($expr);
		die;
	}

	private function updateScopeForVariableAssign(Scope $scope, Op $node): Scope
	{
		if ($node instanceof Assign) {
			$scope = $this->processAssign($scope, $node);

			return $scope;
		}

		dump($node);
		die;

		return $scope;
	}

	private function isExprResolvable($expr): bool
	{
		if ($expr instanceof Operand\Literal) {
			return true;
		} elseif ($expr instanceof Operand\Variable) {
			return $this->isExprResolvable($expr->ops[0]);

		} elseif ($expr instanceof Operand\Temporary) {
			if (!empty($expr->ops)) {
				return $this->isExprResolvable($expr->ops[0]);
			} elseif ($expr->original) {
				return $this->isExprResolvable($expr->original);
			}

			dump('??');
			die;
//			return $this->isExprResolvable($expr->ops[0]); // all ops?
		} elseif ($expr instanceof Assign) {
			return $this->isExprResolvable($expr->expr);
		} elseif ($expr instanceof BinaryOp\Concat) {
			return $this->isExprResolvable($expr->left) && $this->isExprResolvable($expr->right);
		}

		return false;
	}

	private function isSafeForFileInclusion($expr): bool
	{
		if ($expr instanceof Operand\Temporary) {
			if (!empty($expr->ops)) {
				return $this->isSafeForFileInclusion($expr->ops[0]);
			}

			return $this->isSafeForFileInclusion($expr->original);
		} elseif ($expr instanceof BinaryOp\Concat) {
			return $this->isSafeForFileInclusion($expr->left) && $this->isSafeForFileInclusion($expr->right);
		} elseif ($expr instanceof Operand\Literal) {
			return true;
		} elseif ($expr instanceof Operand\Variable) {
			if (!empty($expr->ops)) {
				return $this->isSafeForFileInclusion($expr->ops[0]);
			}
		} elseif ($expr instanceof Op\Expr\FuncCall) {
			return $this->transitionFunction->isSanitizer($expr->name, 'file');
		} elseif ($expr instanceof Assign) {
			return $this->isSafeForFileInclusion($expr->expr);
		}

		return false;
	}

	private function findFuncCallMapping(Func $function, array $bindArgs): ?FuncCallMapping
	{
		foreach ($this->funcCallMappings as $mapping) {
			if ($mapping->match($function, $bindArgs)) {
				return $mapping;
			}
		}

		return null;
	}

}